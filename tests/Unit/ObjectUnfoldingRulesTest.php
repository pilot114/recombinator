<?php

declare(strict_types=1);

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NodeConnectingVisitor;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as Printer;
use Recombinator\Transformation\Visitor\ClosureCallInlinerVisitor;
use Recombinator\Transformation\Visitor\NewArgHoistVisitor;
use Recombinator\Transformation\Visitor\ObjectMethodInlinerVisitor;
use Recombinator\Transformation\Visitor\ObjectStateSpecializerVisitor;

function applyUnfoldVisitor(string $code, PhpParser\NodeVisitor $visitor): string
{
    $ast = new ParserFactory()->createForNewestSupportedVersion()->parse($code) ?? [];
    $ast = new NodeTraverser(new NodeConnectingVisitor())->traverse($ast);
    $ast = new NodeTraverser($visitor)->traverse($ast);

    return new Printer()->prettyPrint($ast);
}

// ── Правило 1: new из аргументов ────────────────────────────────────────────

$registry = '<?php class P {} class C { public function register($p) { $p->provide($this); } '
    . 'public function make($p) { return $p; } } $c = new C(); ';

it('hoists new of a declared class out of procedure call arguments', function () use ($registry): void {
    $result = applyUnfoldVisitor($registry . '$c->register(new P()); $c->register(new P());', new NewArgHoistVisitor());
    expect($result)
        ->toContain("\$p1 = new P();\n\$c->register(\$p1);\n\$p2 = new P();\n\$c->register(\$p2);");
});

it('keeps new in value-returning calls: other rules treat it as a value', function () use ($registry): void {
    $result = applyUnfoldVisitor($registry . '$x = $c->make(new P());', new NewArgHoistVisitor());
    expect($result)->toContain('$x = $c->make(new P());');
});

it('does not hoist new after an argument with side effects', function () use ($registry): void {
    $result = applyUnfoldVisitor($registry . '$c->register(g(), new P());', new NewArgHoistVisitor());
    expect($result)->toContain('$c->register(g(), new P());');
});

it('does not hoist new of unknown class', function () use ($registry): void {
    $result = applyUnfoldVisitor($registry . '$c->register(new External());', new NewArgHoistVisitor());
    expect($result)->toContain('$c->register(new External());');
});

// ── Правило 2: процедуры живого объекта ─────────────────────────────────────

it('inlines procedure that passes $this further, substituting the object variable', function (): void {
    $code = '<?php
class C { public function register($p) { $p->provide($this); } }
$c = new C();
$p1 = new P();
$c->register($p1);';
    $result = applyUnfoldVisitor($code, new ObjectMethodInlinerVisitor());
    expect($result)->toContain('$p1->provide($c);')->not->toContain('$c->register(');
});

it('does not inline procedure touching private members of a live object', function (): void {
    $code = '<?php
class C { private array $a = []; public function set($k, $v) { $this->a[$k] = $v; } }
$c = new C();
$c->set("x", 1);';
    $result = applyUnfoldVisitor($code, new ObjectMethodInlinerVisitor());
    expect($result)->toContain('$c->set("x", 1);');
});

it('keeps nested closure parameters untouched when inlining', function (): void {
    $code = '<?php
class D { public function provide($c) { $c->set("a", static function ($c) { return $c->get("s"); }); } }
$d = new D();
$d->provide($cnt);';
    $result = applyUnfoldVisitor($code, new ObjectMethodInlinerVisitor());
    expect($result)->toContain('$cnt->set("a", static function ($c) {')->toContain('return $c->get("s");');
});

// ── Правило 3: состояние объекта ────────────────────────────────────────────

$container = '<?php
final class Box {
    private array $items;
    private array $factories;
    public function __construct(array $items = []) { $this->items = $items; $this->factories = []; }
    public function has(string $id): bool { return \array_key_exists($id, $this->items); }
    public function set(string $id, mixed $v): void { $this->items[$id] = $v; }
    public function resolved(string $id): bool {
        if (!$this->has($id)) { throw new \LogicException(); }
        return !$this->items[$id] instanceof \Closure;
    }
    public function get(string $id): mixed {
        if (!$this->resolved($id)) {
            $entry = \call_user_func($this->items[$id], $this);
            if (\array_key_exists($id, $this->factories)) { return $entry; }
            $this->items[$id] = $entry;
        }
        return $this->items[$id];
    }
}
';

it('specializes a read of known array state to a literal', function () use ($container): void {
    $result = applyUnfoldVisitor(
        $container . '$b = new Box(["cfg" => ["debug" => true]]); $cfg = $b->get("cfg");',
        new ObjectStateSpecializerVisitor()
    );
    expect($result)->toContain('$cfg = ["debug" => true];');
});

it('specializes lazy entry: closure call, memo write through public setter', function () use ($container): void {
    $result = applyUnfoldVisitor(
        $container . '$b = new Box(); $b->set("app", static function ($c) { return 42; }); $app = $b->get("app");',
        new ObjectStateSpecializerVisitor()
    );
    expect($result)
        ->toMatch('/\$get_entry_\d+ = \\\\call_user_func\(static function \(\$c\) \{\s+return 42;\s+\}, \$b\);/')
        ->toMatch('/\$b->set\("app", \$get_entry_\d+\);/')
        ->toMatch('/\$app = \$get_entry_\d+;/');
});

it('stops tracking after the object escapes into unknown code', function () use ($container): void {
    $result = applyUnfoldVisitor(
        $container . '$b = new Box(["cfg" => 1]); unknown($b); $cfg = $b->get("cfg");',
        new ObjectStateSpecializerVisitor()
    );
    expect($result)->toContain('$cfg = $b->get("cfg");');
});

it('does not specialize reads inside loops', function () use ($container): void {
    $result = applyUnfoldVisitor(
        $container . '$b = new Box(["cfg" => 1]); foreach ($xs as $x) { echo $b->get("cfg"); $b->set("cfg", $x); }',
        new ObjectStateSpecializerVisitor()
    );
    expect($result)->toContain('echo $b->get("cfg");');
});

it('does not use a variable value that is reassigned later', function () use ($container): void {
    $result = applyUnfoldVisitor(
        $container . '$v = 1; $b = new Box(); $b->set("k", $v); $v = 2; $r = $b->get("k");',
        new ObjectStateSpecializerVisitor()
    );
    expect($result)->toContain('$r = $b->get("k");');
});

// ── Правило 4: вызов замыкания ──────────────────────────────────────────────

it('inlines call_user_func of a closure literal with return value', function (): void {
    $result = applyUnfoldVisitor(
        '<?php $r = \call_user_func(static function ($c) { $s = $c->get("x"); return f($s); }, $cnt);',
        new ClosureCallInlinerVisitor()
    );
    expect($result)
        ->toMatch('/\$closure_s_\d+ = \$cnt->get\("x"\);/')
        ->toMatch('/\$r = f\(\$closure_s_\d+\);/')
        ->not->toContain('function');
});

it('inlines immediately invoked closure with captured variables', function (): void {
    $result = applyUnfoldVisitor('<?php $a = 1; $r = (function ($x) use ($a) { return $x + $a; })(2);', new ClosureCallInlinerVisitor());
    expect($result)->toContain('$r = 2 + $a;');
});

it('copies captured variable that the closure body modifies', function (): void {
    $result = applyUnfoldVisitor('<?php $a = 1; (function () use ($a) { $a++; echo $a; })(); echo $a;', new ClosureCallInlinerVisitor());
    expect($result)
        ->toMatch('/\$closure_a_(\d+) = \$a;\s+\$closure_a_\1\+\+;\s+echo \$closure_a_\1;\s+echo \$a;/');
});

it('does not inline closure with early return', function (): void {
    $result = applyUnfoldVisitor('<?php $r = (function ($x) { if ($x) { return 1; } return 2; })($y);', new ClosureCallInlinerVisitor());
    expect($result)->toContain('function ($x)');
});

// ── ObjectArrayTrackerVisitor: неизвестный состав коллекции ─────────────────

$shelter = '<?php
class Dog { public function species() { return "dog"; } }
class Shelter {
    private array $animals = [];
    public function admit(Dog ...$in): int { $n = 0; foreach ($in as $a) { $this->animals[] = $a; ++$n; } return $n; }
    public function all(): \Generator { yield from $this->animals; }
}
$s = new Shelter();
$rex = new Dog();
';

it('does not replace collection with empty array when admitted items are unknown', function () use ($shelter): void {
    $result = applyUnfoldVisitor(
        $shelter . '$batch = make(); $s->admit(...$batch); foreach ($s->all() as $a) { echo 1; }',
        new Recombinator\Transformation\Visitor\ObjectArrayTrackerVisitor()
    );
    expect($result)->toContain('foreach ($s->all() as $a)');
});

it('resolves admit call used inside an expression', function () use ($shelter): void {
    $result = applyUnfoldVisitor(
        $shelter . 'echo "n={$s->admit(...[$rex])}"; foreach ($s->all() as $a) { echo 1; }',
        new Recombinator\Transformation\Visitor\ObjectArrayTrackerVisitor()
    );
    expect($result)->toContain('echo "n={1}";')->toContain('foreach ([0 => $rex] as $a)');
});

it('gives up (without errors) when a method reads an unknown value inside an expression', function (): void {
    $code = '<?php
class Auth { private $user; public function __construct($u) { $this->user = $u; } public function login($p) { return $this->user . "_" . $p; } }
$a = new Auth($_GET["u"]);
$r = $a->login("x");';
    $result = applyUnfoldVisitor($code, new ObjectStateSpecializerVisitor());
    expect($result)->toContain('$r = $a->login("x");');
});

// ── MethodToSingleReturnVisitor: области видимости вложенных замыканий ──────

it('does not substitute local variables into nested closure parameters', function (): void {
    $code = '<?php function f($key) { $doSave = true; return (function ($item = null, bool &$doSave = true) use ($key) { return $doSave; })(); }';
    $result = applyUnfoldVisitor($code, new Recombinator\Transformation\Visitor\MethodToSingleReturnVisitor());
    expect($result)->toContain('bool &$doSave = true')->toContain('return $doSave;');
});

it('keeps stable props known after escape despite unrelated dynamic method calls', function () use ($container): void {
    $result = applyUnfoldVisitor(
        $container . '$b = new Box(); $b->set("app", static function ($c) { return 42; }); $o->$m(); $app = $b->get("app");',
        new ObjectStateSpecializerVisitor()
    );
    expect($result)->toMatch('/\$app = \$get_entry_\d+;/');
});

it('treats a method named by a string literal as possibly called', function (): void {
    $code = '<?php
final class Box {
    private array $items = [];
    private array $flags = [];
    public function set(string $id, mixed $v): void { $this->items[$id] = $v; }
    public function flag(string $id): void { $this->flags[$id] = true; }
    public function get(string $id): mixed {
        $entry = \call_user_func($this->items[$id], $this);
        if (\array_key_exists($id, $this->flags)) { return $entry; }
        return $entry . "!";
    }
}
$b = new Box();
$b->set("app", static function ($c) { return 42; });
call_user_func([$o, "flag"], "app");
$app = $b->get("app");';
    $result = applyUnfoldVisitor($code, new ObjectStateSpecializerVisitor());
    expect($result)->toContain('$app = $b->get("app");');
});

// ── StripLocalClassTypeHintsVisitor: readonly требует тип ───────────────────

it('keeps local class type on readonly properties', function (): void {
    $code = '<?php class Conn {} class Result { public function __construct(private readonly Conn $connection, Conn $other) {} } '
        . 'readonly class R { public function __construct(public Conn $c) {} } class P { private Conn $a; private readonly Conn $b; }';
    $result = applyUnfoldVisitor($code, new Recombinator\Transformation\Visitor\StripLocalClassTypeHintsVisitor());
    expect($result)
        ->toContain('private readonly Conn $connection, $other')
        ->toContain('public Conn $c')
        ->toContain('private $a;')
        ->toContain('private readonly Conn $b;');
});

// ── FlattenInheritanceVisitor: многоуровневое наследование ──────────────────

it('gives unique names to parent copies across inheritance levels', function (): void {
    $code = '<?php
class A { public function __construct(?string $n = null) { $this->n = $n; } }
class B extends A { public function __construct(string $n) { parent::__construct($n); } }
class C extends B { public function __construct(string $id) { parent::__construct($id); } }';
    $flatten = static function (string $code): string {
        for ($i = 0; $i < 3; $i++) {
            $code = '<?php ' . applyUnfoldVisitor($code, new Recombinator\Transformation\Visitor\FlattenInheritanceVisitor());
        }

        return $code;
    };
    $result = $flatten($code);
    expect(substr_count($result, 'function __parent___construct('))->toBeLessThanOrEqual(2)
        ->and($result)->toContain('function __parent___parent___construct(')
        ->and($result)->toContain('$this->__parent___parent___construct($id);');
});
