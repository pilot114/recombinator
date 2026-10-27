<?php

declare(strict_types=1);

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NodeConnectingVisitor;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as Printer;
use Recombinator\Transformation\Visitor\PrivateMethodInlinerVisitor;

function applyPrivateInline(string $code): string
{
    $ast = new ParserFactory()->createForNewestSupportedVersion()->parse('<?php ' . $code) ?? [];
    $ast = new NodeTraverser(new NodeConnectingVisitor())->traverse($ast);
    $ast = new NodeTraverser(new PrivateMethodInlinerVisitor())->traverse($ast);

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
    'public method'    => ['public function m($v) { return $v; }', '$this->m($x)'],
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

    expect($result)->toContain('return ["`" . $name . "`", "`" . "t" . "`", null !== null, $this->quote($other)];')
        ->and($result)->toContain('return $this->quote($name);');
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
