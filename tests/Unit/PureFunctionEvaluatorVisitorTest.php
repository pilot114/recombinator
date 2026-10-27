<?php

declare(strict_types=1);

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as Printer;
use Recombinator\Transformation\Visitor\PureFunctionEvaluatorVisitor;

function applyPureEvaluator(string $code): string
{
    $ast = new ParserFactory()->createForNewestSupportedVersion()->parse($code) ?? [];
    $ast = new NodeTraverser(new PureFunctionEvaluatorVisitor())->traverse($ast);

    return new Printer()->prettyPrint($ast);
}

it('evaluates pure function call with constant args', function (): void {
    $result = applyPureEvaluator('<?php function add($a, $b) { return $a + $b; } $r = add(3, 4);');
    expect($result)->toContain('$r = 7;');
});

it('leaves expressions that throw TypeError at evaluation untouched', function (): void {
    $result = applyPureEvaluator('<?php $a = "x" + []; $b = 1 % 0; echo $a, $b;');
    expect($result)
        ->toContain('$a = "x" + [];')
        ->toContain('$b = 1 % 0;');
});

it('does not fold arguments whose evaluation emits a warning', function (): void {
    $result = applyPureEvaluator('<?php function f($s) { return $s; } $r = f([1] . "x");');
    expect($result)->toContain('$r = f([1] . "x");');
});

it('does not use a constant assigned in another scope', function (): void {
    $result = applyPureEvaluator('<?php function h(array $i) { return implode(" ", $i); }
        class U { function a() { $id = []; return $id; } function b($d) { $id = g($d); return h($id); } }');
    expect($result)->toContain('return h($id);');
});
