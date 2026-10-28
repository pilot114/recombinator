<?php

declare(strict_types=1);

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NodeConnectingVisitor;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as Printer;
use Recombinator\Transformation\Visitor\CallSiteInlinerVisitor;

function applyCallSiteInliner(string $code): string
{
    $ast = new ParserFactory()->createForNewestSupportedVersion()->parse('<?php ' . $code) ?? [];
    $ast = new NodeTraverser(new NodeConnectingVisitor())->traverse($ast);
    $ast = new NodeTraverser(new CallSiteInlinerVisitor())->traverse($ast);

    return new Printer()->prettyPrint($ast);
}

/** Вывод программы до и после встраивания совпадает. */
function sameOutput(string $code): array
{
    $run = static function (string $source): string {
        $file = tempnam(sys_get_temp_dir(), 'csi');
        file_put_contents($file, "<?php\n" . $source);
        $output = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' 2>&1');
        unlink($file);

        return $output;
    };

    $result = applyCallSiteInliner($code);

    return [$run($code), $run($result), $result];
}

it('inlines a single-call function with several statements', function (): void {
    [$before, $after, $result] = sameOutput(
        'function connect(array $params, $config = null) { $driver = strtoupper($params["driver"]); return $driver . ":" . ($config ?? "none"); }
        $driver = "outer"; echo connect(["driver" => "sqlite"]), " ", $driver, "\n";',
    );

    expect($result)->not->toContain('echo connect(')->toContain('$driver1 = strtoupper')
        ->and($after)->toBe($before)->toBe("SQLITE:none outer\n");
});

it('turns early returns into breaks of a do-while wrapper', function (): void {
    [$before, $after, $result] = sameOutput(
        'function kind(int $n): string { foreach ([1, 2, 3] as $i) { if ($i === $n) { return "small"; } } if ($n > 100) { return "big"; } return "medium"; }
        $x = kind("2") . "/" ; echo $x, "\n";',
    );

    expect($result)->toContain('do {')->toContain('break 2;')->toContain('$n1 = (int) $n1;')
        ->and($after)->toBe($before)->toBe("small/\n");
});

it('keeps functions called conditionally, recursively or by name', function (): void {
    $result = applyCallSiteInliner(
        'function a() { return 1; } function b() { return 2; } function c($n) { return $n ? c($n - 1) : 0; } function d() { return 4; }
        $x = $y && a(); $z = $obj->m() . b(); echo c(3); $f = "d"; echo d();',
    );

    expect($result)->toContain('$y && a()')->toContain('$spill1 = $obj->m();')->toContain('echo c(3)')->toContain('echo d()');
});

it('substitutes unchanged parameters and the final return expression directly', function (): void {
    [$before, $after, $result] = sameOutput(
        'function objToStr(object $obj): string { return $obj instanceof \Stringable ? (string) $obj : get_debug_type($obj) . "@x"; }
        function label(string $prefix, int $n) { $s = $prefix . ":" . $n; return strtoupper($s); }
        $entity = new stdClass(); echo sprintf("state %s", objToStr($entity)), " "; echo label("id", 5), "\n";',
    );

    expect($result)->toContain('sprintf("state %s", $entity instanceof \Stringable ? (string) $entity : get_debug_type($entity) . "@x")')
        ->toContain('$s1 = "id" . ":" . 5;')->toContain('strtoupper($s1)')
        ->not->toContain('Result')
        ->and($after)->toBe($before);
});

it('inlines a single-call method of a known receiver class', function (): void {
    [$before, $after, $result] = sameOutput(
        'final class Flattener { public function __construct(private array $map) {}
            public function flatten(string $key): string { $value = $this->map[$key] ?? "none"; return strtoupper($value); } }
        final class Persister { private Flattener $flattener; public function __construct() { $this->flattener = new Flattener(["a" => "x"]); }
            public function id(string $k) { $id = $this->flattener->flatten($k); return $id . "!"; } }
        echo (new Persister())->id("a"), "\n";',
    );

    // Свойство private $map видно только в Flattener: метод не встраивается в другой класс
    expect($result)->toContain('$this->flattener->flatten($k)')->toContain('private array $map')
        ->and($after)->toBe($before)->toBe("X!\n");
});

it('does not inline methods using parent or closures bound to $this', function (): void {
    $result = applyCallSiteInliner(
        'class Base { public function base() { return 1; } } class A extends Base { public function m() { return parent::base(); } public function n() { return fn() => $this; } }
        final class B { public function go(A $a) { return [$a->m(), $a->n()]; } }',
    );

    expect($result)->toContain('$a->m()')->toContain('$a->n()');
});

it('inlines a method reading public properties of the receiver', function (): void {
    [$before, $after, $result] = sameOutput(
        'final class Flattener { public function __construct(public array $map) {}
            public function flatten(string $key): string { $value = $this->map[$key] ?? "none"; return strtoupper($value); } }
        final class Persister { public function __construct(private Flattener $flattener) {}
            public function id(string $k) { return $this->flattener->flatten($k) . "!"; } }
        echo (new Persister(new Flattener(["a" => "x"])))->id("a"), json_encode(new Flattener([1])), "\n";',
    );

    expect($result)->toContain('$flattener1->map[')->and($after)->toBe($before);
});

it('spills earlier impure arguments into temporaries to keep evaluation order', function (): void {
    [$before, $after, $result] = sameOutput(
        'function make() { echo "make "; return "faker"; }
        class C { public function get($k) { echo "get "; return $k; } }
        class D { public function get($k) { echo "D "; return $k; } }
        class Action { public function __construct(public $em, public $faker) {} }
        $c = count($argv) > 0 ? new C() : new D(); $a = new Action($c->get("em"), make()); echo $a->em, $a->faker, "\n";',
    );

    expect($result)->toContain('$spill1 = $c->get("em");')->toContain('new Action($spill1,')
        ->and($after)->toBe($before)->toBe("get make emfaker\n");
});

it('inlines a small function at each of a few call sites', function (): void {
    [$before, $after, $result] = sameOutput(
        'function wrap(string $s) { $t = "[" . $s; return $t . "]"; }
        echo wrap("a"); echo wrap("b"); echo wrap(strtoupper("c")), "\n";',
    );

    expect($result)->not->toContain('echo wrap(')->toContain('$t1 = "[" . "a";')->toContain('$t2 = "[" . "b";')
        ->and($after)->toBe($before)->toBe("[a][b][C]\n");
});

it('inlines static calls with static:: bound to the called class', function (): void {
    [$before, $after, $result] = sameOutput(
        'abstract class Factory { protected static string $creator; public static function available(): bool { return static::$creator !== "" && class_exists(static::$creator); } }
        class Slim extends Factory { protected static string $creator = "Slim\\\\Missing"; }
        class Nyholm extends Factory { protected static string $creator = "Nyholm"; }
        class Nyholm2 {}
        function pick() { return [Slim::available(), Nyholm::available()]; }
        var_dump(pick());',
    );

    expect($result)->toContain('Slim::$creator')->toContain('Nyholm::$creator')->not->toContain('Slim::available()')
        ->and($after)->toBe($before);
});

it('inlines static methods called through an object', function (): void {
    [$before, $after, $result] = sameOutput(
        'class Provider { public static function factories(): array { return ["A", "B"]; } }
        function pick() { $p = new Provider(); foreach ($p->factories() as $f) { echo $f; } }
        pick();',
    );

    expect($result)->not->toContain('->factories()')->and($after)->toBe($before);
});
