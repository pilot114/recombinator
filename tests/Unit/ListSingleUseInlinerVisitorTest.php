<?php

declare(strict_types=1);

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NodeConnectingVisitor;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;
use Recombinator\Transformation\Visitor\ListSingleUseInlinerVisitor;

function applyListInliner(string $code): string
{
    $parser  = new ParserFactory()->createForNewestSupportedVersion();
    $printer = new StandardPrinter();
    $ast     = $parser->parse($code) ?? [];

    $t1 = new NodeTraverser();
    $t1->addVisitor(new NodeConnectingVisitor());
    $ast = $t1->traverse($ast);

    $t2 = new NodeTraverser();
    $t2->addVisitor(new ListSingleUseInlinerVisitor());
    $ast = $t2->traverse($ast);

    return $printer->prettyPrint($ast);
}

it('inlines single used list variable to direct index access', function (): void {
    $code = '<?php
function sum2($a, $b) {
    list($s, $p) = func($a, $b, 0);
    return $s;
}';
    $result = applyListInliner($code);

    expect($result)->toContain('return func($a, $b, 0)[0]');
    expect($result)->not->toContain('list(');
    expect($result)->not->toContain('$s');
    expect($result)->not->toContain('$p');
});

it('uses correct index for non-first element', function (): void {
    $code = '<?php
function foo() {
    list($a, $b, $c) = bar();
    return $b;
}';
    $result = applyListInliner($code);

    expect($result)->toContain('return bar()[1]');
    expect($result)->not->toContain('$a');
    expect($result)->not->toContain('$b');
    expect($result)->not->toContain('$c');
});

it('does not inline when multiple variables are used', function (): void {
    $code = '<?php
function foo() {
    list($s, $p) = bar();
    return [$s, $p];
}';
    $result = applyListInliner($code);

    // Both $s and $p used → inlining would call bar() twice
    expect($result)->toContain('list(');
    expect($result)->toContain('$s');
    expect($result)->toContain('$p');
});

it('does not inline when variable is read more than once', function (): void {
    $code = '<?php
function foo() {
    list($s, $p) = bar();
    return $s + $s;
}';
    $result = applyListInliner($code);

    expect($result)->toContain('$s');
});

it('preserves surrounding statements', function (): void {
    $code = '<?php
function foo($x) {
    $y = $x + 1;
    list($s, $unused) = compute($y);
    return $s * 2;
}';
    $result = applyListInliner($code);

    expect($result)->toContain('$y = $x + 1');
    expect($result)->toContain('return compute($y)[0] * 2');
    expect($result)->not->toContain('$s');
});
