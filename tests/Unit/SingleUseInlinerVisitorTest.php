<?php

declare(strict_types=1);

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NodeConnectingVisitor;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;
use Recombinator\Transformation\Visitor\SingleUseInlinerVisitor;

function applyInliner(string $code): string
{
    $parser  = new ParserFactory()->createForHostVersion();
    $printer = new StandardPrinter();

    $ast = $parser->parse($code) ?? [];

    $t1 = new NodeTraverser();
    $t1->addVisitor(new NodeConnectingVisitor());

    $ast = $t1->traverse($ast);

    $t2 = new NodeTraverser();
    $t2->addVisitor(new SingleUseInlinerVisitor());

    $ast = $t2->traverse($ast);

    return $printer->prettyPrint($ast);
}

// ── Basic inlining ────────────────────────────────────────────────────────────

it('inlines a single-use variable with a scalar RHS', function (): void {
    $code   = '<?php $x = 42; echo $x;';
    $result = applyInliner($code);

    expect($result)->toContain('echo 42');
    expect($result)->not->toContain('$x = 42');
});

it('inlines a single-use variable with a concat expression', function (): void {
    $code   = '<?php $msg = $a . " world"; echo $msg;';
    $result = applyInliner($code);

    expect($result)->toContain('echo $a . " world"');
    expect($result)->not->toContain('$msg =');
});

it('inlines a ternary expression', function (): void {
    $code   = '<?php $r = $a > 0 ? "pos" : "neg"; echo $r;';
    $result = applyInliner($code);

    expect($result)->toContain('echo');
    expect($result)->toContain('"pos"');
    expect($result)->not->toContain('$r =');
});

// ── The primary motivating example ───────────────────────────────────────────

it('inlines a comparison-ternary expression into the echo', function (): void {
    $code = '<?php
$result = $username . \'_\' . $pass === \'test_test\' ? \'success\' : \'fail\';
echo $result . "\n";';

    $result = applyInliner($code);

    expect($result)->not->toContain('$result =');
    expect($result)->toContain('echo');
    expect($result)->toContain("'success'");
    expect($result)->toContain("'fail'");
});

// ── Non-inlining cases ────────────────────────────────────────────────────────

it('does not inline when variable is read more than once', function (): void {
    $code   = '<?php $x = 42; echo $x; echo $x;';
    $result = applyInliner($code);

    expect($result)->toContain('$x = 42');
    expect($result)->toContain('echo $x');
});

it('does not inline when variable is written more than once', function (): void {
    $code   = '<?php $x = 1; $x = 2; echo $x;';
    $result = applyInliner($code);

    // Multiple writes → cannot inline safely
    expect($result)->toContain('echo $x');
});

it('does not inline when the only read is inside the assignment RHS itself', function (): void {
    // $x appears once as LHS (write) and once as RHS (read in own assign) — not a separate use
    $code   = '<?php $x = $x ?? "default"; echo $x;';
    $result = applyInliner($code);

    // readCount of $x outside the assignment is 1 (the echo), writeCount is 1 → should inline?
    // Actually: in RHS ($x ?? "default"), $x is a read → readCount = 2 (RHS + echo)
    // → NOT inlined
    expect($result)->toContain('$x =');
});

// ── Self-referential assignment is never inlined ──────────────────────────────

it('does not inline $x = $x + 1 pattern', function (): void {
    // $x read in RHS counts toward readCounts, making readCount ≥ 2 when used elsewhere
    // or the read is inside the assignment node itself → skipped
    $code   = '<?php $x = 10; $x = $x + 1; echo $x;';
    $result = applyInliner($code);

    // writeCount($x) = 2 → not eligible
    expect($result)->toContain('$x');
});

it('does not inline into increment (write context)', function (): void {
    $result = applyInliner('<?php function f($xs) { $n = 0; foreach ($xs as $x) { $a = "e" . $n++; g($a); } }');
    expect($result)->toContain('$n = 0;')->toContain('$n++');
});

it('does not inline a variable read inside a loop that does not contain the assignment', function (): void {
    $result = applyInliner('<?php $v = expensive(); foreach ($xs as $x) { echo $v; }');
    expect($result)->toContain('$v = expensive();')->toContain('echo $v;');
});

it('treats same-named variables in different functions as different variables', function (): void {
    $result = applyInliner('<?php function a() { $x = 1; } function b($x) { return $x; }');
    expect($result)->toContain('$x = 1;')->toContain('return $x;');
});

it('does not inline into parameter declaration when param is reassigned and captured by closure', function (): void {
    $code = '<?php class A { public function get($key, callable $callback) { '
        . '$callback = static function ($item) use ($callback) { return $callback($item); }; return g($key, $callback); } }';
    $result = applyInliner($code);
    expect($result)->toContain('public function get($key, callable $callback)');
});

it('does not inline a call into string interpolation', function (): void {
    $result = applyInliner('<?php $body = implode(",", $xs); echo "log: $body";');
    expect($result)->toContain('$body = implode')->toContain('"log: {$body}"');
});

it('does not move a computation past other effects', function (string $code, string $kept): void {
    expect(applyInliner($code))->toContain($kept);
})->with([
    'finally'        => ['<?php function h() { try { $r = f(); } finally { g(); } return $r; }', '$r = f();'],
    'statement'      => ['<?php $a = f(); g(); echo $a;', '$a = f();'],
    'earlier call'   => ['<?php $a = f(); echo g() . $a;', '$a = f();'],
]);

it('inlines into the next statement when nothing runs before the read', function (): void {
    expect(applyInliner('<?php $a = f(); echo $a . g();'))->toContain('echo f() . g();');
});

it('does not inline into a by-reference parameter of a method', function (): void {
    $result = applyInliner('<?php class U { function persist($e) { $visited = []; $this->doPersist($e, $visited); }
        private function doPersist($e, array &$visited) {} }');

    expect($result)->toContain('$this->doPersist($e, $visited);');
});
