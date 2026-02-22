<?php

declare(strict_types=1);

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NodeConnectingVisitor;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;
use Recombinator\Transformation\Visitor\CoalesceNullRemoveVisitor;

function applyCoalesceRemove(string $code): string
{
    $parser  = new ParserFactory()->createForHostVersion();
    $printer = new StandardPrinter();

    $ast = $parser->parse($code) ?? [];

    $t1 = new NodeTraverser();
    $t1->addVisitor(new NodeConnectingVisitor());

    $ast = $t1->traverse($ast);

    $t2 = new NodeTraverser();
    $t2->addVisitor(new CoalesceNullRemoveVisitor());

    $ast = $t2->traverse($ast);

    return $printer->prettyPrint($ast);
}

it('removes trailing ?? null from variable', function (): void {
    $result = applyCoalesceRemove('<?php $x = $a ?? null;');
    expect($result)->toContain('$x = $a');
    expect($result)->not->toContain('?? null');
});

it('removes trailing ?? null from scalar', function (): void {
    $result = applyCoalesceRemove('<?php $x = "hello" ?? null;');
    expect($result)->toContain('$x = "hello"');
    expect($result)->not->toContain('?? null');
});

it('removes nested trailing ?? null in one pass', function (): void {
    // $a ?? ($b ?? null) → $a ?? $b
    $result = applyCoalesceRemove('<?php $x = $a ?? $b ?? null;');
    expect($result)->toContain('$x = $a ?? $b');
    expect($result)->not->toContain('?? null');
});

it('does not remove ?? when right side is not null', function (): void {
    $result = applyCoalesceRemove('<?php $x = $a ?? "default";');
    expect($result)->toContain('$x = $a ?? "default"');
});

it('does not remove ?? when right side is a variable', function (): void {
    $result = applyCoalesceRemove('<?php $x = $a ?? $b;');
    expect($result)->toContain('$x = $a ?? $b');
});

it('removes ?? null in function argument', function (): void {
    $result = applyCoalesceRemove('<?php foo($a ?? null);');
    expect($result)->toContain('foo($a)');
    expect($result)->not->toContain('?? null');
});

it('removes ?? null produced by BinaryAndIsset pattern', function (): void {
    // Simulates: $username = $source ?? ($username ?? null)
    $result = applyCoalesceRemove('<?php $username = $source ?? ($username ?? null);');
    expect($result)->toContain('$username = $source ?? $username');
    expect($result)->not->toContain('?? null');
});
