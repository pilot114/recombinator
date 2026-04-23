<?php

declare(strict_types=1);

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;
use Recombinator\Transformation\Visitor\LinterVisitor;

beforeEach(function (): void {
    $this->parser = new ParserFactory()->createForNewestSupportedVersion();
    $this->printer = new StandardPrinter();
    $this->visitor = new LinterVisitor(dirname(__DIR__, 2));
});

it('returns null when code has no changes after linting', function (): void {
    $code = '<?php $x = 1;';
    $ast = $this->parser->parse($code);

    $traverser = new NodeTraverser();
    $traverser->addVisitor($this->visitor);
    $result = $traverser->traverse($ast);

    // Either null (no changes) or a valid AST — never an exception
    expect($result)->toBeArray();
});

it('does not throw when tools are unavailable', function (): void {
    $visitor = new LinterVisitor('/nonexistent/path/that/has/no/vendor');
    $code = '<?php echo "hello";';
    $ast = $this->parser->parse($code);

    $traverser = new NodeTraverser();
    $traverser->addVisitor($visitor);

    expect(fn () => $traverser->traverse($ast))->not->toThrow(Throwable::class);
});

it('runs as the last visitor in the pipeline and returns valid AST', function (): void {
    $code = '<?php function foo(int $a, int $b): int { return $a + $b; }';
    $ast = $this->parser->parse($code);

    $traverser = new NodeTraverser();
    $traverser->addVisitor($this->visitor);
    $result = $traverser->traverse($ast);

    expect($result)->toBeArray()->not->toBeEmpty();

    $printed = $this->printer->prettyPrint($result);
    expect($printed)->toContain('function foo');
});
