<?php

declare(strict_types=1);

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;
use Recombinator\Transformation\Visitor\RemoveCommentsVisitor;

beforeEach(function (): void {
    $this->parser  = new ParserFactory()->createForHostVersion();
    $this->printer = new StandardPrinter();
    $this->visitor = new RemoveCommentsVisitor();
});

function transform(string $code, RemoveCommentsVisitor $visitor, StandardPrinter $printer, \PhpParser\Parser $parser): string
{
    $ast = $parser->parse($code) ?? [];
    $t   = new NodeTraverser();
    $t->addVisitor($visitor);
    return $printer->prettyPrint($t->traverse($ast));
}

it('removes single-line // comments', function (): void {
    $result = transform('<?php // comment
$x = 1;', $this->visitor, $this->printer, $this->parser);

    expect($result)->not->toContain('// comment')
        ->toContain('$x = 1');
});

it('removes multi-line /* */ comments', function (): void {
    $result = transform('<?php /* block comment */ $x = 1;', $this->visitor, $this->printer, $this->parser);

    expect($result)->not->toContain('/* block comment */')
        ->toContain('$x = 1');
});

it('removes PHPDoc /** */ comments', function (): void {
    $result = transform('<?php /** @param int $x */ function foo(int $x) {}', $this->visitor, $this->printer, $this->parser);

    expect($result)->not->toContain('@param')
        ->toContain('function foo');
});

it('removes all comment types simultaneously', function (): void {
    $code = '<?php
// line comment
/* block comment */
/** docblock */
function foo() { return 1; }
';
    $result = transform($code, $this->visitor, $this->printer, $this->parser);

    expect($result)
        ->not->toContain('// line comment')
        ->not->toContain('/* block comment */')
        ->not->toContain('docblock')
        ->toContain('function foo()');
});

it('preserves code structure when there are no comments', function (): void {
    $code   = '<?php $x = 1 + 2;';
    $result = transform($code, $this->visitor, $this->printer, $this->parser);

    expect($result)->toContain('$x = 1 + 2');
});

it('removes inline comments inside functions', function (): void {
    $code = '<?php
function bar() {
    // inner comment
    return 42;
}';
    $result = transform($code, $this->visitor, $this->printer, $this->parser);

    expect($result)->not->toContain('inner comment')
        ->toContain('return 42');
});
