<?php

declare(strict_types=1);

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as Printer;
use Recombinator\Transformation\Visitor\InstanceofSimplifierVisitor;

function applyInstanceofSimplifier(string $code): string
{
    $ast = new ParserFactory()->createForNewestSupportedVersion()->parse('<?php ' . $code) ?? [];

    return new Printer()->prettyPrint(new NodeTraverser(new InstanceofSimplifierVisitor())->traverse($ast));
}

it('folds instanceof along a fully declared extends chain', function (): void {
    $classes = 'class A {} class B extends A {} class C {} $b = new B();';

    expect(applyInstanceofSimplifier($classes . ' echo $b instanceof A;'))->toContain('echo true;')
        ->and(applyInstanceofSimplifier($classes . ' echo $b instanceof C;'))->toContain('echo false;');
});

it('keeps instanceof while the hierarchy is not fully known', function (string $code): void {
    expect(applyInstanceofSimplifier($code))->toContain('instanceof');
})->with([
    'class not declared yet'  => ['$b = new B(); echo $b instanceof A;'],
    'parent not declared'     => ['class B extends X {} $b = new B(); echo $b instanceof A;'],
    'interface'               => ['interface I {} class B implements I {} $b = new B(); echo $b instanceof I;'],
    'reassigned variable'     => ['class A {} class B {} $b = new B(); $b = new A(); echo $b instanceof A;'],
    'names inside namespace'  => ['namespace N; class B {} $b = new B(); echo $b instanceof \\Other\\B;'],
]);
