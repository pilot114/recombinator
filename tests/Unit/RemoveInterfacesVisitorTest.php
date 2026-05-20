<?php

declare(strict_types=1);

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NodeConnectingVisitor;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as Printer;
use Recombinator\Transformation\Visitor\RemoveInterfacesVisitor;

function applyRemoveInterfaces(string $code): string
{
    $parser = new ParserFactory()->createForNewestSupportedVersion();
    $ast    = $parser->parse($code) ?? [];

    $t1 = new NodeTraverser();
    $t1->addVisitor(new NodeConnectingVisitor());

    $ast = $t1->traverse($ast);

    $t2 = new NodeTraverser();
    $t2->addVisitor(new RemoveInterfacesVisitor());

    $ast = $t2->traverse($ast);

    return new Printer()->prettyPrint($ast);
}

it('removes interface declaration', function (): void {
    $result = applyRemoveInterfaces('<?php interface Countable { public function count(): int; }');
    expect($result)->not->toContain('interface Countable');
});

it('removes implements from class', function (): void {
    $result = applyRemoveInterfaces('<?php class Foo implements Bar { }');
    expect($result)
        ->toContain('class Foo')
        ->not->toContain('implements');
});

it('removes multiple implements', function (): void {
    $result = applyRemoveInterfaces('<?php class Foo implements Bar, Baz, Qux { }');
    expect($result)
        ->toContain('class Foo')
        ->not->toContain('implements');
});

it('keeps class body intact', function (): void {
    $result = applyRemoveInterfaces('<?php class Foo implements Bar { public function x(): int { return 1; } }');
    expect($result)
        ->toContain('public function x()')
        ->not->toContain('implements');
});

it('removes interface but keeps sibling class', function (): void {
    $code = '<?php interface Runnable {} class Worker implements Runnable { public function run(): void {} }';
    $result = applyRemoveInterfaces($code);
    expect($result)
        ->not->toContain('interface Runnable')
        ->toContain('class Worker')
        ->not->toContain('implements')
        ->toContain('public function run()');
});

it('does not touch classes without implements', function (): void {
    $result = applyRemoveInterfaces('<?php class Plain { public function foo(): void {} }');
    expect($result)
        ->toContain('class Plain')
        ->toContain('public function foo()')
        ->not->toContain('implements');
});
