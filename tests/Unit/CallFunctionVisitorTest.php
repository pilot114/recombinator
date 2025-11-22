<?php

declare(strict_types=1);

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;
use PhpParser\Node;
use Recombinator\ScopeStore;
use Recombinator\Visitor\CallFunctionVisitor;
use Recombinator\Visitor\ScopeVisitor;

beforeEach(function () {
    $this->parser = (new ParserFactory())->createForHostVersion();
    $this->printer = new StandardPrinter();
    $this->store = new ScopeStore();
    $this->visitor = new CallFunctionVisitor($this->store);
});

it('can inline simple function call', function () {
    $code = '<?php
function getNumber() {
    return 42;
}

$result = getNumber();';

    // First, manually register the function in store
    $returnExpr = new Node\Scalar\LNumber(42);
    $this->store->setFunctionToGlobal('getNumber', $returnExpr);

    $ast = $this->parser->parse($code);
    $traverser = new NodeTraverser();
    $traverser->addVisitor($this->visitor);
    $ast = $traverser->traverse($ast);

    $result = $this->printer->prettyPrint($ast);

    expect($result)->toContain('$result = 42');
});

it('can inline function with expression', function () {
    $code = '<?php
function double($x) {
    return $x * 2;
}

$result = double(5);';

    // Register function body
    $param = new Node\Param(new Node\Expr\Variable('x'));
    $returnExpr = new Node\Expr\BinaryOp\Mul(
        new Node\Expr\Variable('x'),
        new Node\Scalar\LNumber(2)
    );
    $this->store->setFunctionToGlobal('double', $returnExpr);

    $ast = $this->parser->parse($code);
    $traverser = new NodeTraverser();
    $traverser->addVisitor(new ScopeVisitor());
    $traverser->addVisitor($this->visitor);
    $ast = $traverser->traverse($ast);

    $result = $this->printer->prettyPrint($ast);

    // Function call should be replaced with expression
    expect($result)->not->toContain('double(5)');
});

it('can inline function returning string', function () {
    $code = '<?php
function getMessage() {
    return "Hello World";
}

$msg = getMessage();';

    // Register function body
    $returnExpr = new Node\Scalar\String_('Hello World');
    $this->store->setFunctionToGlobal('getMessage', $returnExpr);

    $ast = $this->parser->parse($code);
    $traverser = new NodeTraverser();
    $traverser->addVisitor($this->visitor);
    $ast = $traverser->traverse($ast);

    $result = $this->printer->prettyPrint($ast);

    expect($result)->toContain("'Hello World'");
});

it('should not inline unknown function', function () {
    $code = '<?php $result = unknownFunction();';

    $ast = $this->parser->parse($code);
    $traverser = new NodeTraverser();
    $traverser->addVisitor($this->visitor);
    $ast = $traverser->traverse($ast);

    $result = $this->printer->prettyPrint($ast);

    // Function call should remain unchanged
    expect($result)->toContain('unknownFunction()');
});

it('can inline multiple function calls', function () {
    $code = '<?php
function getOne() {
    return 1;
}

function getTwo() {
    return 2;
}

$sum = getOne() + getTwo();';

    // Register both functions
    $this->store->setFunctionToGlobal('getOne', new Node\Scalar\LNumber(1));
    $this->store->setFunctionToGlobal('getTwo', new Node\Scalar\LNumber(2));

    $ast = $this->parser->parse($code);
    $traverser = new NodeTraverser();
    $traverser->addVisitor($this->visitor);
    $ast = $traverser->traverse($ast);

    $result = $this->printer->prettyPrint($ast);

    expect($result)->toContain('$sum = 1 + 2');
});

it('retrieves function from scope store', function () {
    $returnExpr = new Node\Scalar\LNumber(100);
    $this->store->setFunctionToGlobal('testFunc', $returnExpr);

    $retrieved = $this->store->getFunctionFromGlobal('testFunc');

    expect($retrieved)->not->toBeNull()
        ->and($retrieved->value)->toBe(100);
});

it('can inline function with boolean return', function () {
    $code = '<?php
function isTrue() {
    return true;
}

$flag = isTrue();';

    // Register function body
    $returnExpr = new Node\Expr\ConstFetch(new Node\Name('true'));
    $this->store->setFunctionToGlobal('isTrue', $returnExpr);

    $ast = $this->parser->parse($code);
    $traverser = new NodeTraverser();
    $traverser->addVisitor($this->visitor);
    $ast = $traverser->traverse($ast);

    $result = $this->printer->prettyPrint($ast);

    expect($result)->toContain('$flag = true');
});
