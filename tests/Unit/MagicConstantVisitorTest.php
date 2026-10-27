<?php

declare(strict_types=1);

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NodeConnectingVisitor;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as Printer;
use Recombinator\Transformation\Visitor\MagicConstantVisitor;

function applyMagicConstant(string $code): string
{
    $ast = new ParserFactory()->createForNewestSupportedVersion()->parse('<?php ' . $code) ?? [];
    $ast = new NodeTraverser(new NodeConnectingVisitor())->traverse($ast);
    $ast = new NodeTraverser(new MagicConstantVisitor())->traverse($ast);

    return new Printer()->prettyPrint($ast);
}

it('substitutes class, method and function names fixed by the declaration place', function (): void {
    $result = applyMagicConstant(
        'class A { function m() { return [__CLASS__, __METHOD__, __FUNCTION__, fn() => __METHOD__, function () { return __CLASS__; }]; } }
        function f() { return [__FUNCTION__, __METHOD__, __NAMESPACE__]; }
        trait T { function t() { return [__CLASS__, __METHOD__]; } }
        $o = new class { function a() { return __CLASS__; } };',
    );

    expect($result)
        ->toContain("return ['A', 'A::m', 'm', fn() => __METHOD__, function () {\n            return 'A';")
        ->toContain("return ['f', 'f', ''];")
        ->toContain('return [__CLASS__, __METHOD__];')
        ->toContain('return __CLASS__;');
});

it('keeps __NAMESPACE__ in namespaced code', function (): void {
    expect(applyMagicConstant('namespace App; function f() { return __NAMESPACE__; }'))->toContain('return __NAMESPACE__;');
});
