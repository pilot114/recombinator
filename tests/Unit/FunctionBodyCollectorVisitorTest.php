<?php

declare(strict_types=1);

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use Recombinator\Domain\ScopeStore;
use Recombinator\Transformation\Visitor\FunctionBodyCollectorVisitor;

beforeEach(
    function (): void {
        $this->parser = new ParserFactory()->createForHostVersion();
        $this->printer = new Standard();
        $this->store = new ScopeStore();
        $this->visitor = new FunctionBodyCollectorVisitor($this->store);
    }
);

it(
    'collects single-return function body into ScopeStore',
    function (): void {
        $code = '<?php function double($x) { return $x * 2; }';
        $ast = $this->parser->parse($code);

        $traverser = new NodeTraverser();
        $traverser->addVisitor($this->visitor);
        $traverser->traverse($ast);

        $body = $this->store->getFunctionFromGlobal('double');

        expect($body)->not->toBeNull();
    }
);

it(
    'keeps the function definition and skips functions with global',
    function (): void {
        $ast = $this->parser->parse('<?php function double($x) { return $x * 2; } function city() { global $s; return $s->city; }');

        $traverser = new NodeTraverser();
        $traverser->addVisitor($this->visitor);
        $traverser->traverse($ast);

        // Определение удалит RemoveUnusedFunctionVisitor, когда вызовов не останется
        expect($ast[0]->getAttribute('remove'))->toBeNull()
            ->and($this->store->getFunctionFromGlobal('city'))->toBeNull();
    }
);

it(
    'ignores multi-statement functions',
    function (): void {
        $code = '<?php function complex($x) { $y = $x * 2; return $y; }';
        $ast = $this->parser->parse($code);

        $traverser = new NodeTraverser();
        $traverser->addVisitor($this->visitor);
        $traverser->traverse($ast);

        $body = $this->store->getFunctionFromGlobal('complex');

        expect($body)->toBeNull();
    }
);
