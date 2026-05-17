<?php

declare(strict_types=1);

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NodeConnectingVisitor;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;
use Recombinator\Core\Config;
use Recombinator\Transformation\Visitor\ClassInlinerVisitor;
use Recombinator\Transformation\Visitor\RemoveUnusedClassVisitor;
use Recombinator\Transformation\Visitor\RemoveUnusedFunctionVisitor;

function applyVisitorWithConfig(string $code, object $visitor): string
{
    $parser  = new ParserFactory()->createForNewestSupportedVersion();
    $printer = new StandardPrinter();
    $ast     = $parser->parse($code) ?? [];

    $t1 = new NodeTraverser();
    $t1->addVisitor(new NodeConnectingVisitor());
    $ast = $t1->traverse($ast);

    $t2 = new NodeTraverser();
    $t2->addVisitor($visitor);

    return $printer->prettyPrint($t2->traverse($ast));
}

it('matches a contract entry by full FQN, short name and method', function (): void {
    $config = new Config()->setPublicContract([
        'App\\Domain\\Money',
        'App\\Service\\PaymentGateway::charge',
        'app_bootstrap',
    ]);

    expect($config->isProtected('App\\Domain\\Money'))->toBeTrue();
    expect($config->isProtected('\\app\\domain\\money'))->toBeTrue();
    expect($config->isProtected('Money'))->toBeTrue();
    expect($config->isProtected('PaymentGateway'))->toBeTrue();
    expect($config->isProtected('app_bootstrap'))->toBeTrue();
    expect($config->isProtected('SomethingElse'))->toBeFalse();
});

it('exposes the registered contract', function (): void {
    $config = new Config()->setPublicContract(['App\\Foo', 'bar']);
    expect($config->getPublicContract())->toEqualCanonicalizing(['app\\foo', 'bar']);
});

it('an empty contract protects nothing', function (): void {
    expect(new Config()->isProtected('Anything'))->toBeFalse();
});

it('keeps an unused class that is part of the public contract', function (): void {
    $code   = '<?php class PublicApi { public $x = 1; }';
    $config = new Config()->setPublicContract(['App\\PublicApi']);

    $result = applyVisitorWithConfig($code, new RemoveUnusedClassVisitor($config));

    expect($result)->toContain('class PublicApi');
});

it('still removes an unused class that is not in the contract', function (): void {
    $code   = '<?php class Internal { public $x = 1; }';
    $config = new Config()->setPublicContract(['App\\PublicApi']);

    $result = applyVisitorWithConfig($code, new RemoveUnusedClassVisitor($config));

    expect($result)->not->toContain('class Internal');
});

it('keeps an unused function that is part of the public contract', function (): void {
    $code   = '<?php function public_entry() { return 42; }';
    $config = new Config()->setPublicContract(['public_entry']);

    $result = applyVisitorWithConfig($code, new RemoveUnusedFunctionVisitor($config));

    expect($result)->toContain('function public_entry');
});

it('does not inline a class that is part of the public contract', function (): void {
    $code   = '<?php class Point { public $x = 0; } $p = new Point(); echo $p->x;';
    $config = new Config()->setPublicContract(['Point']);

    $result = applyVisitorWithConfig($code, new ClassInlinerVisitor($config));

    expect($result)->toContain('class Point');
    expect($result)->toContain('new Point');
});
