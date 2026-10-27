<?php

declare(strict_types=1);

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitor\NodeConnectingVisitor;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as Printer;
use Recombinator\Domain\ScopeStore;
use Recombinator\Transformation\Visitor\ConstructorAndMethodsVisitor;
use Recombinator\Transformation\Visitor\EnumCaseInlinerVisitor;
use Recombinator\Transformation\Visitor\FlattenInheritanceVisitor;
use Recombinator\Transformation\Visitor\PreExecutionVisitor;
use Recombinator\Transformation\Visitor\PropertyStateTrackerVisitor;
use Recombinator\Transformation\Visitor\RemoveUnusedPureVisitor;
use Recombinator\Transformation\Visitor\VarToScalarVisitor;

/*
 * Преобразования, которые меняли вывод программы (найдены запуском итога Syntax Zoo).
 */

function applySemantics(string $code, NodeVisitor $visitor): string
{
    $ast = new ParserFactory()->createForNewestSupportedVersion()->parse($code) ?? [];
    $ast = new NodeTraverser(new NodeConnectingVisitor())->traverse($ast);
    $ast = new NodeTraverser($visitor)->traverse($ast);

    return new Printer()->prettyPrint($ast);
}

it('does not substitute the initial property value of a variable reassigned in a loop', function (): void {
    $result = applySemantics(
        '<?php class C { public function __construct(public int $v) {} }
        $c = new C(0);
        for ($i = 1; $i <= 3; $i++) { $c = new C($c->v + $i); }
        echo $c->v;',
        new PropertyStateTrackerVisitor(),
    );

    expect($result)->toContain('new C($c->v + $i)')->toContain('echo $c->v;');
});

it('does not substitute property values inside functions', function (): void {
    $result = applySemantics(
        '<?php class C { public function __construct(public int $v) {} } $c = new C(1); function f($c) { return $c->v; }',
        new PropertyStateTrackerVisitor(),
    );

    expect($result)->toContain('return $c->v;');
});

it('does not scalarize an object whose variable is reassigned', function (): void {
    $result = applySemantics(
        '<?php class C { public function __construct(public int $v) {} } $c = new C(0); while (f()) { $c = new C($c->v + 1); } echo $c->v;',
        new ConstructorAndMethodsVisitor(new ScopeStore()),
    );

    expect($result)->toContain('$c = new C(0);');
});

it('keeps static properties of the parent shared after flattening', function (): void {
    $result = applySemantics(
        '<?php abstract class A { protected static array $reg = []; public function __construct() { static::$reg[static::class] = 1; } }
        class B extends A {}',
        new FlattenInheritanceVisitor(),
    );

    expect($result)
        ->toContain('public static array $reg = [];')
        ->toContain('A::$reg[static::class] = 1;')
        ->and(substr_count($result, '$reg = []'))->toBe(1);
});

it('does not remove an unused object whose constructor has side effects', function (): void {
    $code = '<?php class Pure { public function __construct(public int $v) { $this->w = $v; } }
        class Counted { public static int $n = 0; public function __construct() { self::$n++; } }
        $a = new Pure(1); $b = new Counted();';
    $result = applySemantics($code, new RemoveUnusedPureVisitor());

    expect($result)->not->toContain('$a = new Pure(1);')->toContain('$b = new Counted();');
});

it('does not pre-execute a static method reading mutable static state', function (): void {
    $code = '<?php class Log { private static array $e = [];
            public static function add(string $m): void { static::$e[] = $m; }
            public static function dump(): string { $s = implode(",", static::$e); return "[" . $s . "]"; } }
        Log::add("x"); echo Log::dump();';
    $result = applySemantics($code, new PreExecutionVisitor(new ScopeStore()));

    expect($result)->toContain('echo Log::dump();');
});

it('rewrites enum types to the backing type when inlining cases', function (): void {
    $result = applySemantics(
        '<?php enum M: string { case A = "a"; } class E { public M $m = M::A; public function get(): ?M { return M::A; } }',
        new EnumCaseInlinerVisitor(),
    );

    expect($result)
        ->toContain('public string $m = "a";')
        ->toContain('public function get(): ?string')
        ->not->toContain('enum M');
});

it('keeps an enum whose cases are used as objects', function (string $use): void {
    $enum   = '<?php enum M: string { case A = "a"; public function up(): string { return "A"; } } ';
    $result = applySemantics($enum . $use, new EnumCaseInlinerVisitor());

    expect($result)->toContain('enum M');
})->with([
    'method on a case'       => '$x = M::A; echo $x->up();',
    'value of a returned case' => 'function f(): M { return M::A; } echo f()->value;',
    'instanceof'             => 'var_dump($y instanceof M);',
    'from()'                 => 'echo M::from("a")->name;',
]);

it('treats enum cases as constants when substituting variables', function (): void {
    $result = applySemantics('<?php enum M: string { case A = "a"; } $s = M::A; f($s);', new VarToScalarVisitor(new ScopeStore()));

    expect($result)->toContain('f(M::A);');
});

it('folds a whole if-return chain in one traversal', function (): void {
    $result = applySemantics(
        '<?php function f($m) { if (a($m)) { return 1; } if (b($m)) { return 2; } if (c($m)) { return 3; } return 4; }',
        new \Recombinator\Transformation\Visitor\TernarReturnVisitor(),
    );

    expect($result)->toContain('return a($m) ? 1 : (b($m) ? 2 : (c($m) ? 3 : 4));');
});

it('does not fold a switch whose cases are not constant', function (): void {
    $result = applySemantics(
        '<?php switch (true) { case f(): $x = 1; break; default: if (g()) { $x = 2; break; } $x = 3; }',
        new \Recombinator\Transformation\Visitor\DeadBranchVisitor(),
    );

    expect($result)->toContain('switch (true)');
});

it('does not fold a switch branch with a nested break', function (): void {
    $result = applySemantics(
        '<?php switch (1) { case 1: if (g()) { $x = 2; break; } $x = 3; }',
        new \Recombinator\Transformation\Visitor\DeadBranchVisitor(),
    );

    expect($result)->toContain('switch (1)');
});

it('strips self return types consistently and keeps them where an external parent declares the method', function (): void {
    $result = applySemantics(
        '<?php interface D { function add($m): self; } class M implements D { function add($m): D { return $this; } }
        class It extends ArrayIterator {}
        class Q implements IteratorAggregate { function getIterator(): It { return new It([]); } function me(): static { return $this; } }',
        new \Recombinator\Transformation\Visitor\StripLocalClassTypeHintsVisitor(),
    );

    expect($result)->toContain('function add($m);')->toContain('function getIterator(): It')->toMatch('/function me\(\)\s*\{/');
});

it('strips nullable and union local types from parameters and propagates to overrides', function (): void {
    $result = applySemantics(
        '<?php interface S {} interface H { function handle(?S $s, int|S $t); } class K implements H { function handle(?S $s, int|S|string $t) {} }',
        new \Recombinator\Transformation\Visitor\StripLocalClassTypeHintsVisitor(),
    );

    expect($result)->toContain('function handle($s, $t);')->toContain('function handle($s, $t)');
});

it('strips return types along a hierarchy whose external root does not declare the method', function (): void {
    $result = applySemantics(
        '<?php class Base extends Exception { static function make(): Base { return new Base(); } }
        class Child extends Base { static function make(): Base { return new Child(); } }',
        new \Recombinator\Transformation\Visitor\StripLocalClassTypeHintsVisitor(),
    );

    expect(substr_count($result, 'make(): Base'))->toBe(0);
});

it('keeps a local return type when an ancestor declares an external one', function (): void {
    $result = applySemantics(
        '<?php class LocalProp extends ReflectionProperty {} interface S { function get(): ?ReflectionProperty; }
        class R implements S { function get(): LocalProp { return new LocalProp("a", "b"); } }',
        new \Recombinator\Transformation\Visitor\StripLocalClassTypeHintsVisitor(),
    );

    expect($result)->toContain('function get(): LocalProp');
});

it('does not flatten a hierarchy whose parent is type-checked', function (string $check): void {
    $result = applySemantics(
        '<?php abstract class P { function f() { return 1; } } class C extends P { function g() { return parent::f(); } } ' . $check,
        new FlattenInheritanceVisitor(),
    );

    expect($result)->toContain('class C extends P');
})->with([
    'instanceof' => '$x = new C(); var_dump($x instanceof P);',
    'param type' => 'function h(P $p) {}',
    'catch'      => 'try {} catch (P $e) {}',
]);

it('does not flatten a class using parent as a type', function (): void {
    $result = applySemantics(
        '<?php abstract class P {} class C extends P { function g($x) { return $x instanceof parent; } }',
        new FlattenInheritanceVisitor(),
    );

    expect($result)->toContain('class C extends P');
});

it('does not scalarize objects inside methods or with non-trivial constructors', function (): void {
    $result = applySemantics(
        '<?php class Id { public $n; function __construct($n) { $this->n = $n; } function q() { return "\"" . $this->n . "\""; } }
        class Loud extends Id { function __construct($n) { parent::__construct($n); $this->n = strtoupper($n); } }
        class P { function f($t) { $t = new Id($t); return $t->q(); } }
        $a = new Loud("x"); echo $a->n; $b = new Id("y"); g($b);',
        new ConstructorAndMethodsVisitor(new ScopeStore()),
    );

    expect($result)
        ->toContain('$t = new Id($t);')
        ->toContain('$a = new Loud("x");')
        ->toContain('$b = new Id("y");');
});

it('keeps property writes that are read elsewhere or escape', function (): void {
    $result = applySemantics(
        '<?php class U { private $path;
            function withPath($p) { $new = clone $this; $new->path = $p; return $new; } function get() { return $this->path; } }
        $a = new stdClass(); $a->dead = 1; $b = new stdClass(); $b->x = 2; var_dump($b);',
        new \Recombinator\Transformation\Visitor\RemoveUnusedPropWriteVisitor(),
    );

    expect($result)->toContain('$new->path = $p;')->toContain('$b->x = 2;')->not->toContain('$a->dead');
});

it('flattens parent:: calls to methods the child does not override', function (): void {
    $result = applySemantics(
        '<?php abstract class P { function setR($r) { $this->r = $r; } } class C extends P { function init() { parent::setR(1); } } $c = new C();',
        new FlattenInheritanceVisitor(),
    );

    expect($result)->toContain('$this->setR(1);')->not->toContain('__parent_');
});

it('keeps a counter whose increment value is used', function (): void {
    $result = applySemantics('<?php foreach ($rows as $v) { $i = 1; foreach ($v as $x) { bind($i++, $x); } }', new RemoveUnusedPureVisitor());

    expect($result)->toContain('$i = 1;');
});

it('does not fold an if-return chain in a function returning by reference', function (): void {
    $result = applySemantics(
        '<?php class S { private array $s = []; public function &get($k): array { if (!isset($this->s[$k])) { return []; } return $this->s[$k]; } }',
        new \Recombinator\Transformation\Visitor\TernarReturnVisitor(),
    );

    expect($result)->toContain('return $this->s[$k];');
});

it('flattens only a parent with a single child and no other uses', function (): void {
    $two = applySemantics(
        '<?php abstract class P { function f() { return 1; } } class A extends P {} class B extends P {}',
        new FlattenInheritanceVisitor(),
    );
    $one = applySemantics('<?php abstract class P { function f() { return 1; } } class A extends P {}', new FlattenInheritanceVisitor());

    expect($two)->toContain('class A extends P')->and($one)->not->toContain('extends');
});

it('inlines function calls only when argument evaluation stays the same', function (string $code, string $kept): void {
    $store = new ScopeStore();
    $ast   = new ParserFactory()->createForNewestSupportedVersion()->parse($code) ?? [];
    $ast   = new NodeTraverser(new \Recombinator\Transformation\Visitor\FunctionBodyCollectorVisitor($store))->traverse($ast);
    $ast   = new NodeTraverser(new \Recombinator\Transformation\Visitor\CallFunctionVisitor($store))->traverse($ast);

    expect(new Printer()->prettyPrint($ast))->toContain($kept);
})->with([
    'recursion'        => ['<?php function f($n) { return $n ? f($n - 1) : 0; } echo f(3);', 'echo f(3);'],
    'mutual recursion' => ['<?php function a($n) { return b($n); } function b($n) { return a($n); } echo a(1);', 'echo a(1);'],
    'duplicated call'  => ['<?php function twice($x) { return $x . $x; } echo twice(g());', 'echo twice(g());'],
    'dropped call'     => ['<?php function first($a, $b) { return $a; } echo first(1, g());', 'echo first(1, g());'],
    'scalar coercion'  => ['<?php function show(int $x) { return var_export($x, true); } echo show("5");', 'echo show("5");'],
    'outer variable'   => ['<?php function add($a) { return $a + $b; } echo add(1);', 'echo add(1);'],
    'named argument'   => ['<?php function id($a) { return $a; } echo id(a: g());', 'echo id(a: g());'],
]);

it('inlines a function with a default value for a missing argument', function (): void {
    $store = new ScopeStore();
    $code  = '<?php function sep($a, $s = ", ") { return $a . $s; } echo sep("x");';
    $ast   = new ParserFactory()->createForNewestSupportedVersion()->parse($code) ?? [];
    $ast   = new NodeTraverser(new \Recombinator\Transformation\Visitor\FunctionBodyCollectorVisitor($store))->traverse($ast);
    $ast   = new NodeTraverser(new \Recombinator\Transformation\Visitor\CallFunctionVisitor($store))->traverse($ast);

    expect(new Printer()->prettyPrint($ast))->toContain('echo "x" . ", ";');
});
