<?php

declare(strict_types=1);

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NodeConnectingVisitor;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as Printer;
use Recombinator\Transformation\Visitor\InlineEmptySubclassVisitor;

function applyInlineEmptySubclass(string $code): string
{
    $ast = new ParserFactory()->createForNewestSupportedVersion()->parse($code) ?? [];
    $ast = new NodeTraverser(new NodeConnectingVisitor())->traverse($ast);
    $ast = new NodeTraverser(new InlineEmptySubclassVisitor())->traverse($ast);

    return new Printer()->prettyPrint($ast);
}

it('replaces an empty subclass with its parent', function (): void {
    $result = applyInlineEmptySubclass('<?php class Base { function __construct($x) {} } class Foo extends Base {} $f = new Foo("x");');
    expect($result)->toContain('$f = new Base("x");');
});

it('keeps empty exception subclasses', function (): void {
    $result = applyInlineEmptySubclass(
        '<?php class Base extends RuntimeException {} class FooException extends Base {} throw new FooException("x");',
    );
    expect($result)->toContain('throw new FooException("x");');
});

it('keeps a subclass that is caught separately', function (): void {
    $result = applyInlineEmptySubclass(
        '<?php class FooException extends RuntimeException {} try { throw new FooException("x"); } catch (FooException $e) {}',
    );
    expect($result)->toContain('throw new FooException("x");')->toContain('catch (FooException $e)');
});

it('keeps a subclass checked with instanceof, ::class or a string', function (string $check): void {
    $result = applyInlineEmptySubclass('<?php class Foo extends Bar {} $x = new Foo(); ' . $check);
    expect($result)->toContain('new Foo()');
})->with([
    '$y = $x instanceof Foo;',
    '$y = Foo::class;',
    '$y = is_a($x, \'Foo\');',
]);

it('keeps a subclass that adds an interface', function (): void {
    $result = applyInlineEmptySubclass('<?php class Foo extends Bar implements Countable {} $x = new Foo();');
    expect($result)->toContain('new Foo()');
});
