<?php

declare(strict_types=1);

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NodeConnectingVisitor;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;
use Recombinator\Transformation\Visitor\RemoveUnusedFunctionVisitor;

function applyRemoveUnusedFunction(string $code): string
{
    $parser  = new ParserFactory()->createForHostVersion();
    $printer = new StandardPrinter();
    $ast     = $parser->parse($code) ?? [];

    $t1 = new NodeTraverser();
    $t1->addVisitor(new NodeConnectingVisitor());
    $ast = $t1->traverse($ast);

    $t2 = new NodeTraverser();
    $t2->addVisitor(new RemoveUnusedFunctionVisitor());
    $ast = $t2->traverse($ast);

    return $printer->prettyPrint($ast);
}

it('removes a function that is never called', function (): void {
    $result = applyRemoveUnusedFunction('<?php function unused() { return 42; }');
    expect($result)->toBe('');
});

it('keeps a function that is called', function (): void {
    $code   = '<?php function greet() { return "hi"; } echo greet();';
    $result = applyRemoveUnusedFunction($code);
    expect($result)->toContain('function greet');
    expect($result)->toContain('greet()');
});

it('removes function after its call sites are gone', function (): void {
    // Simulates the state after PureFunctionEvaluatorVisitor replaced the call with a literal
    $code   = '<?php function add(int $a, int $b): int { return $a + $b; } $result = 7;';
    $result = applyRemoveUnusedFunction($code);
    expect($result)->not->toContain('function add');
    expect($result)->toContain('$result = 7');
});

it('keeps a recursive function (internal call counts as usage)', function (): void {
    $code = '<?php function factorial(int $n): int { return $n <= 1 ? 1 : $n * factorial($n - 1); }';
    $result = applyRemoveUnusedFunction($code);
    // The recursive call inside makes it appear "used" — conservative, safe
    expect($result)->toContain('function factorial');
});

it('removes multiple unused functions independently', function (): void {
    $code = '<?php
function foo() { return 1; }
function bar() { return 2; }
function baz() { return 3; }
echo baz();
';
    $result = applyRemoveUnusedFunction($code);
    expect($result)->not->toContain('function foo');
    expect($result)->not->toContain('function bar');
    expect($result)->toContain('function baz');
});

it('removes unused function but keeps used one', function (): void {
    $code = '<?php
function used(int $x): int { return $x * 2; }
function unused(int $x): int { return $x + 1; }
$a = used(5);
';
    $result = applyRemoveUnusedFunction($code);
    expect($result)->toContain('function used');
    expect($result)->not->toContain('function unused');
});
