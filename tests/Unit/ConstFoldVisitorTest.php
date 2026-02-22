<?php

declare(strict_types=1);

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NodeConnectingVisitor;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;
use Recombinator\Transformation\Visitor\ConstFoldVisitor;

beforeEach(function (): void {
    $this->parser  = new ParserFactory()->createForHostVersion();
    $this->printer = new StandardPrinter();
});

function applyConstFold(string $code): string
{
    $parser  = new ParserFactory()->createForHostVersion();
    $printer = new StandardPrinter();

    $ast = $parser->parse($code) ?? [];

    // NodeConnectingVisitor sets parent refs (needed by BaseVisitor)
    $t1 = new NodeTraverser();
    $t1->addVisitor(new NodeConnectingVisitor());

    $ast = $t1->traverse($ast);

    $t2 = new NodeTraverser();
    $t2->addVisitor(new ConstFoldVisitor());

    $ast = $t2->traverse($ast);

    return $printer->prettyPrint($ast);
}

// ── Identical (===) ───────────────────────────────────────────────────────────

it('folds identical strings to true', function (): void {
    $result = applyConstFold('<?php $x = "test" === "test";');
    expect($result)->toContain('$x = true');
});

it('folds non-identical strings to false', function (): void {
    $result = applyConstFold('<?php $x = "a" === "b";');
    expect($result)->toContain('$x = false');
});

it('folds identical integers to true', function (): void {
    $result = applyConstFold('<?php $x = 42 === 42;');
    expect($result)->toContain('$x = true');
});

// ── Not identical (!==) ───────────────────────────────────────────────────────

it('folds not-identical to true when values differ', function (): void {
    $result = applyConstFold('<?php $x = "a" !== "b";');
    expect($result)->toContain('$x = true');
});

it('folds not-identical to false when values match', function (): void {
    $result = applyConstFold('<?php $x = "a" !== "a";');
    expect($result)->toContain('$x = false');
});

// ── Comparison operators ──────────────────────────────────────────────────────

it('folds less-than comparison', function (): void {
    $result = applyConstFold('<?php $x = 1 < 2;');
    expect($result)->toContain('$x = true');
});

it('folds greater-than comparison', function (): void {
    $result = applyConstFold('<?php $x = 5 > 10;');
    expect($result)->toContain('$x = false');
});

// ── Ternary folding ───────────────────────────────────────────────────────────

it('folds ternary with true condition to if-branch', function (): void {
    $result = applyConstFold('<?php $x = true ? "yes" : "no";');
    expect($result)->toContain('$x = "yes"');
    expect($result)->not->toContain('"no"');
});

it('folds ternary with false condition to else-branch', function (): void {
    $result = applyConstFold('<?php $x = false ? "yes" : "no";');
    expect($result)->toContain('$x = "no"');
    expect($result)->not->toContain('"yes"');
});

it('does not fold ternary with variable condition', function (): void {
    $result = applyConstFold('<?php $x = $cond ? "yes" : "no";');
    expect($result)->toContain('$cond ?');
});

// ── End-to-end: comparison feeds into ternary ────────────────────────────────

it('folds comparison result into ternary in one pass (bottom-up)', function (): void {
    // "test" === "test" → true → ternary folds to "success"
    $result = applyConstFold('<?php $r = "test" === "test" ? "success" : "fail";');
    expect($result)->toContain('$r = "success"');
    expect($result)->not->toContain('"fail"');
});

it('folds false comparison into ternary else branch', function (): void {
    $result = applyConstFold('<?php $r = "a" === "b" ? "success" : "fail";');
    expect($result)->toContain('$r = "fail"');
    expect($result)->not->toContain('"success"');
});

// ── Non-foldable cases ────────────────────────────────────────────────────────

it('does not fold comparison when one operand is a variable', function (): void {
    $result = applyConstFold('<?php $x = $a === "test";');
    expect($result)->toContain('$a ===');
});
