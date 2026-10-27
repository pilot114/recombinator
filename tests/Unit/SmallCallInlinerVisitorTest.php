<?php

declare(strict_types=1);

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NodeConnectingVisitor;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as Printer;
use Recombinator\Transformation\Visitor\SmallCallInlinerVisitor;

/** @param array<string, string> $files */
function applyPrivateInline(string $code, array $files = []): string
{
    $ast = new ParserFactory()->createForNewestSupportedVersion()->parse('<?php ' . $code) ?? [];
    $ast = new NodeTraverser(new NodeConnectingVisitor())->traverse($ast);
    $ast = new NodeTraverser(new SmallCallInlinerVisitor($files))->traverse($ast);

    return new Printer()->prettyPrint($ast);
}

it('inlines single-return private methods called on $this and self', function (): void {
    $result = applyPrivateInline(
        'class A { private $items = [];
            private function isEmpty($v, $strict = false) { return $v === null || $strict && $v === ""; }
            private static function wrap($v) { return [$v]; }
            private function count() { return \count($this->items); }
            private function forward($a, $b) { return $this->sum($a, 1, $b); }
            public function run($x) { return [$this->isEmpty($x), self::wrap($x), $this->count(), $this->forward(f(), g())]; } }',
    );

    expect($result)->toContain('return [$x === null || false && $x === "", [$x], \count($this->items), $this->sum(f(), 1, g())];');
});

it('keeps calls whose inlining could change evaluation', function (string $method, string $call): void {
    expect(applyPrivateInline("class A { {$method} public function run(\$x) { return {$call}; } }"))->toContain($call);
})->with([
    'scalar param'     => ['private function m(int $v) { return $v + 1; }', '$this->m($x)'],
    'scalar return'    => ['private function m($v): string { return $v; }', '$this->m($x)'],
    'float param'      => ['private function m(float $v) { return $v; }', '$this->m(1.5)'],
    'nullable to strict' => ['private function m(string $v) { return $v; } private function n(?string $x) { return $this->m($x); }', '$this->m($x)'],
    'impure twice'     => ['private function m($v) { return $v . $v; }', '$this->m(f())'],
    'unused impure'    => ['private function m($v) { return 1; }', '$this->m(f())'],
    'reordered'        => ['private function m($a, $b) { return $this->s($b, $a); }', '$this->m(f(), g())'],
    'mutable and call' => ['private function m($a) { return $this->s() + $a; }', '$this->m($this->p)'],
    'by-ref'           => ['private function m(&$v) { return $v; }', '$this->m($x)'],
    'local variable'   => ['private function m() { return $y; }', '$this->m()'],
    'recursion'        => ['private function m($v) { return $v ? $this->m(0) : 1; }', '$this->m($x)'],
    'static via this'  => ['private static function m($v) { return $v; }', '$this->m($x)'],
]);

it('inlines scalar-typed methods when argument and result types already match', function (): void {
    $result = applyPrivateInline(
        'class A {
            private function quote(string $s): string { return "`" . $s . "`"; }
            private function isSet(?int $v): bool { return $v !== null; }
            public function run(string $name, $other) { $name2 = $name; return [$this->quote($name), $this->quote("t"), $this->isSet(null), $this->quote($other)]; }
            public function changed(string $name) { $name = 5; return $this->quote($name); } }',
    );

    // Параметр string только в конкатенации: приведение то же, встраивается и при неизвестном типе
    expect($result)->toContain('return ["`" . $name . "`", "`" . "t" . "`", null !== null, "`" . $other . "`"];')
        ->and($result)->toContain('return "`" . $name . "`";');
});

it('infers scalar result types of own methods, internal functions and ternaries', function (): void {
    $result = applyPrivateInline(
        'class A {
            private function norm(string $id): string { return $this->trim(\strtolower($id)); }
            private function trim(string $s): string { return $s; }
            private function pick(string $s): string { return \preg_match("/a/", $s) === 1 ? \strtoupper($s) : $s; }
            private function size($s): int { return \strpos($s, "x"); }
            public function run(string $id) { return [$this->norm($id), $this->pick($id), $this->size($id)]; } }',
    );

    expect($result)->toContain('return [\strtolower($id), \preg_match("/a/", $id) === 1 ? \strtoupper($id) : $id, $this->size($id)];');
});

it('keeps a call when a call inside the body could change an aliased argument', function (): void {
    $result = applyPrivateInline(
        'class A { private $ref; private function m($a) { return $this->s() + $a; }
            public function run($x) { $this->ref = &$x; return $this->m($x); } }',
    );

    expect($result)->toContain('return $this->m($x);');
});

it('inlines one-line functions', function (): void {
    $result = applyPrivateInline(
        'function quoted(string $v) { return new Identifier($v, true); }
        function rec($v) { return $v ? rec(0) : 1; }
        function usesThis() { return $this; }
        if (!function_exists("poly")) { function poly($v) { return $v; } }
        echo quoted("a"), rec(1), usesThis(), poly(2);',
    );

    expect($result)->toContain('echo new Identifier("a", true), rec(1), usesThis(), poly(2);');
});

it('devirtualizes $this, static and self calls unless a subclass overrides the method', function (): void {
    $result = applyPrivateInline(
        'class Base { const T = 1; private $secret; protected $open;
            public function kind() { return self::T; }
            public function peek() { return $this->secret; }
            public function open() { return $this->open; }
            public static function make($v) { return [$v]; } }
        class Leaf extends Base {
            public function run() { return [$this->kind(), $this->peek(), $this->open(), static::make(1), self::make(2)]; } }',
    );

    expect($result)->toContain('return [\Base::T, $this->peek(), $this->open, [1], [2]];');
});

it('keeps $this calls that a subclass, an anonymous class or outside code may override', function (string $extra, array $files): void {
    $result = applyPrivateInline(
        'class A { public function m() { return 1; } public function run() { return $this->m(); } } ' . $extra,
        $files,
    );

    expect($result)->toContain('return $this->m();');
})->with([
    'subclass'  => ['class B extends A { public function m() { return 2; } }', []],
    'anonymous' => ['$o = new class extends A {};', []],
    'external'  => ['$c = "V\\\\C";', ['vendor/C.php' => '<?php namespace V; class C extends \\A { function m() { return 3; } }']],
]);

it('inlines a scalar parameter that is only passed on to the same type', function (): void {
    $result = applyPrivateInline(
        'class Id { public function __construct(string $v, bool $q) {} }
        function quoted(string $v) { return new Id($v, true); }
        function label(string $v) { return "<" . $v . ">"; }
        function count1(string $v) { return \strlen($v) + 1; }
        function twice(int $v) { return new Id($v, true); }
        function registry() { return Reg::$r ??= make(); }
        echo quoted($x), label($y), count1($z), twice($w), registry();',
    );

    // count1: аргумент — переменная верхнего уровня, а в теле есть вызов: порядок не гарантирован
    expect($result)->toContain('echo new Id($x, true), "<" . $y . ">", count1($z), twice($w), Reg::$r ??= make();');
});

it('inlines calls on receivers whose declared type has a single implementation', function (): void {
    $result = applyPrivateInline(
        'interface Named { public function name(); }
        class Person implements Named { public $title; private $secret;
            public function name() { return $this->title; }
            public function hidden() { return $this->secret; } }
        class Worker extends Person {}
        class Greeter { private Named $who;
            public function hello(Person $p, Named $n) { return [$p->name(), $n->name(), $p->hidden(), $this->who->name()]; } }',
    );

    expect($result)->toContain('return [$p->title, $n->title, $p->hidden(), $this->who->title];');
});

it('keeps typed calls with several implementations, entities or reassigned receivers', function (string $code): void {
    expect(applyPrivateInline($code))->toContain('return $p->name();');
})->with([
    'override'   => ['class P { public $t; public function name() { return $this->t; } } class Q extends P { public function name() { return 1; } } class G { function f(P $p) { return $p->name(); } }'],
    'entity'     => ['#[Entity] class P { public $t; public function name() { return $this->t; } } class G { function f(P $p) { return $p->name(); } }'],
    'reassigned' => ['class P { public $t; public function name() { return $this->t; } } class G { function f(P $p) { $p = g(); return $p->name(); } }'],
    'late static' => ['class P { public function name() { return static::class; } } class G { function f(P $p) { return $p->name(); } }'],
]);

it('infers receiver types of locals and call chains from declarations', function (): void {
    $result = applyPrivateInline(
        'class Item { public $v; public function value() { return $this->v; } public function self(): static { return $this; } }
        class Box { public function item(): Item { return new Item(); } }
        class G {
            function f(Box $b) { $i = new Item(); $j = $b->item(); $k = null; $k = clone $i;
                return [$i->value(), $j->value(), $k->value(), $b->item()->value()]; }
            function mixed($x) { $i = $x ? new Item() : g(); return $i->value(); } }',
    );

    expect($result)->toContain('return [$i->v, $j->v, $k->v, (new Item())->v];')
        ->and($result)->toContain('return $i->value();');
});
