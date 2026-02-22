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
