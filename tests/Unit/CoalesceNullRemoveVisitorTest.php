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

it('keeps ?? null that guards an undefined variable, key or property', function (string $expr): void {
    $result = applyCoalesceRemove('<?php $x = ' . $expr . ' ?? null;');
    expect($result)->toContain('?? null');
})->with(['$a', '$a["k"]', '$o->p', 'C::$p', '$a ?? $b']);

it('removes trailing ?? null from a call result', function (): void {
    $result = applyCoalesceRemove('<?php $x = f() ?? null;');
    expect($result)->toContain('$x = f();');
});

it('removes trailing ?? null from scalar', function (): void {
    $result = applyCoalesceRemove('<?php $x = "hello" ?? null;');
    expect($result)->toContain('$x = "hello"');
    expect($result)->not->toContain('?? null');
});

it('removes nested trailing ?? null in one pass', function (): void {
    $result = applyCoalesceRemove('<?php $x = $a ?? f() ?? null;');
    expect($result)->toContain('$x = $a ?? f()');
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
    $result = applyCoalesceRemove('<?php foo(bar() ?? null);');
    expect($result)->toContain('foo(bar())');
    expect($result)->not->toContain('?? null');
});

it('keeps ?? null produced by BinaryAndIsset pattern', function (): void {
    // $username может быть не определена: без ?? null — «Undefined variable»
    $result = applyCoalesceRemove('<?php $username = $source ?? ($username ?? null);');
    expect($result)->toContain('$username = $source ?? $username ?? null');
});
