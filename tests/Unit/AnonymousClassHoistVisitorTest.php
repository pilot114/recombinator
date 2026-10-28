<?php

declare(strict_types=1);

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NodeConnectingVisitor;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as Printer;
use Recombinator\Transformation\Visitor\AnonymousClassHoistVisitor;

it('hoists anonymous classes into named top-level classes', function (): void {
    $ast = new ParserFactory()->createForNewestSupportedVersion()->parse(
        '<?php interface Handler {} class Dispatcher { public function add($m) { return new class($m) implements Handler { public function __construct(public $m) {} }; } }
        $x = new class { public $a = 1; };',
    ) ?? [];
    $ast = new NodeTraverser(new NodeConnectingVisitor())->traverse($ast);
    $ast = new NodeTraverser(new AnonymousClassHoistVisitor())->traverse($ast);

    $result = new Printer()->prettyPrint($ast);

    expect($result)->toContain('return new Dispatcher_anonymous_1($m);')
        ->toContain('class Dispatcher_anonymous_1 implements Handler')
        ->toContain('$x = new Main_anonymous_1();')
        ->toContain('class Main_anonymous_1');

    eval(str_replace(['Handler', 'Dispatcher', 'Main_'], ['H' . ($u = uniqid()), 'D' . $u, 'M' . $u], $result));
});
