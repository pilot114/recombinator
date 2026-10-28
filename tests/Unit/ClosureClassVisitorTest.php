<?php

declare(strict_types=1);

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as Printer;
use Recombinator\Transformation\Visitor\ClosureClassVisitor;

function closureClass(string $code): array
{
    $ast    = new ParserFactory()->createForNewestSupportedVersion()->parse('<?php ' . $code) ?? [];
    $ast    = new NodeTraverser(new ParentConnectingVisitor())->traverse($ast);

    $result = new Printer()->prettyPrint(new NodeTraverser(new ClosureClassVisitor())->traverse($ast));
    $run    = static fn(string $c): string => (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($c) . ' 2>&1');

    return [$run($code), $run($result), $result];
}

it('turns a single-method class into a closure', function (): void {
    [$before, $after, $result] = closureClass(
        'class Creator { protected $target; protected string $method; private string $suffix = "!";
            public function __construct($target, string $method) { $this->target = $target; $this->method = $method; }
            public function create() { return ' . \Closure::class . '::fromCallable([$this->target, $this->method])() . $this->suffix; } }
        class Source { public function fromGlobals() { return "req"; } }
        function make(): Creator { return new Creator(new Source(), "fromGlobals"); }
        $c = make();
        echo $c->create();',
    );

    expect($result)->not->toContain('class Creator')->toContain('static fn($target, string $method) => static fn() =>')->toContain('$c()')
        ->and($after)->toBe($before)->toBe('req!');
});

it('keeps classes whose objects escape', function (): void {
    [, , $result] = closureClass(
        'class Creator { public function __construct(private $x) {} public function create() { return $this->x; } }
        $c = new Creator(1); var_dump($c); echo $c->create();',
    );

    expect($result)->toContain('class Creator');
});

it('keeps classes named in strings or instanceof', function (): void {
    [, , $result] = closureClass(
        'class Creator { public function create() { return 1; } }
        $c = new Creator(); echo $c instanceof Creator ? $c->create() : 0;',
    );

    expect($result)->toContain('class Creator');
});

it('keeps constructor defaults and types', function (): void {
    [$before, $after] = closureClass(
        'class Emitter { private int $size; public function __construct(int $size = 4096) { $this->size = $size; }
            public function emit(string $s): void { echo strlen($s) . "/" . $this->size; } }
        new Emitter()->emit("abc"); new Emitter("7")->emit("x");',
    );

    expect($after)->toBe($before)->toBe('3/40961/7');
});
