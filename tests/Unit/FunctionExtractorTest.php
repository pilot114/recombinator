<?php

declare(strict_types=1);

use PhpParser\Node;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use Recombinator\Domain\FunctionCandidate;
use Recombinator\Transformation\FunctionExtractor;
use Recombinator\Domain\SideEffectType;

beforeEach(function () {
    $this->parser = (new ParserFactory())->createForHostVersion();
    $this->printer = new Standard();
    $this->extractor = new FunctionExtractor();
});

it('extracts simple pure block into function', function () {
    $code = '<?php
    $a = 1;
    $b = 2;
    $result = $a + $b;';
    $ast = $this->parser->parse($code);

    $candidate = new FunctionCandidate(
        nodes: $ast,
        effectType: SideEffectType::PURE,
        size: 3,
        complexity: 5,
        usedVariables: [],
        definedVariables: ['$a', '$b', '$result'],
        returnVariable: '$result',
        startLine: 1,
        endLine: 4
    );

    $result = $this->extractor->extract($candidate);

    expect($result->functionName)->toContain('calculate');
    expect($result->hasReturn())->toBeTrue();
    expect($result->getParameterCount())->toBe(0);
});

it('extracts function with parameters', function () {
    $code = '<?php
    $result = $x + $y;';
    $ast = $this->parser->parse($code);

    $candidate = new FunctionCandidate(
        nodes: $ast,
        effectType: SideEffectType::PURE,
        size: 1,
        complexity: 2,
        usedVariables: ['$x', '$y'],
        definedVariables: ['$result'],
        returnVariable: '$result',
        startLine: 1,
        endLine: 2
    );

    $result = $this->extractor->extract($candidate);

    expect($result->getParameterCount())->toBe(2);
    expect($result->parameters)->toContain('$x', '$y');
});

it('extracts function with custom name', function () {
    $candidate = new FunctionCandidate(
        nodes: [],
        effectType: SideEffectType::PURE,
        size: 1,
        complexity: 2,
        usedVariables: [],
        definedVariables: [],
    );

    $result = $this->extractor->extract($candidate, 'myCustomFunction');

    expect($result->functionName)->toBe('myCustomFunction');
});

it('creates function with PHPDoc comment', function () {
    $candidate = new FunctionCandidate(
        nodes: [],
        effectType: SideEffectType::PURE,
        size: 5,
        complexity: 10,
        usedVariables: ['$x', '$y'],
        definedVariables: [],
        returnVariable: '$result',
        startLine: 1,
        endLine: 5
    );

    $result = $this->extractor->extract($candidate);

    $comments = $result->function->getAttribute('comments');
    expect($comments)->not->toBeEmpty();

    $docComment = $comments[0]->getText();
    expect($docComment)->toContain('Pure computation');
    expect($docComment)->toContain('@param');
    expect($docComment)->toContain('@return');
    expect($docComment)->toContain('Complexity: 10');
    expect($docComment)->toContain('Effect type:');
});

it('creates function for IO operations', function () {
    $code = '<?php
    echo "Hello";
    echo "World";';
    $ast = $this->parser->parse($code);

    $candidate = new FunctionCandidate(
        nodes: $ast,
        effectType: SideEffectType::IO,
        size: 2,
        complexity: 3,
        usedVariables: [],
        definedVariables: [],
        startLine: 1,
        endLine: 3
    );

    $result = $this->extractor->extract($candidate);

    expect($result->functionName)->toContain('print');
    expect($result->hasReturn())->toBeFalse();

    $comments = $result->function->getAttribute('comments');
    $docComment = $comments[0]->getText();
    expect($docComment)->toContain('I/O operations');
});

it('creates function call with parameters', function () {
    $candidate = new FunctionCandidate(
        nodes: [],
        effectType: SideEffectType::PURE,
        size: 1,
        complexity: 2,
        usedVariables: ['$x', '$y', '$z'],
        definedVariables: [],
        returnVariable: '$result',
    );

    $result = $this->extractor->extract($candidate, 'testFunc');

    expect($result->call)->toBeInstanceOf(Node\Stmt\Expression::class);

    $assign = $result->call->expr;
    expect($assign)->toBeInstanceOf(Node\Expr\Assign::class);

    $funcCall = $assign->expr;
    expect($funcCall)->toBeInstanceOf(Node\Expr\FuncCall::class);
    expect(count($funcCall->args))->toBe(3);
});

it('creates function call without assignment for non-returning functions', function () {
    $candidate = new FunctionCandidate(
        nodes: [],
        effectType: SideEffectType::IO,
        size: 2,
        complexity: 3,
        usedVariables: ['$message'],
        definedVariables: [],
        returnVariable: null,
    );

    $result = $this->extractor->extract($candidate, 'printMessage');

    expect($result->call)->toBeInstanceOf(Node\Stmt\Expression::class);
    expect($result->call->expr)->toBeInstanceOf(Node\Expr\FuncCall::class);
});

it('generates valid PHP code for extracted function', function () {
    $code = '<?php
    $distance = sqrt($x * $x + $y * $y);';
    $ast = $this->parser->parse($code);

    $candidate = new FunctionCandidate(
        nodes: $ast,
        effectType: SideEffectType::PURE,
        size: 1,
        complexity: 8,
        usedVariables: ['$x', '$y'],
        definedVariables: ['$distance'],
        returnVariable: '$distance',
        startLine: 1,
        endLine: 2
    );

    $result = $this->extractor->extract($candidate, 'calculateDistance');

    $functionCode = $this->printer->prettyPrint([$result->function]);

    expect($functionCode)->toContain('function calculateDistance');
    expect($functionCode)->toContain('$x');
    expect($functionCode)->toContain('$y');
    expect($functionCode)->toContain('return $distance');
});

it('handles external state operations', function () {
    $code = '<?php
    $username = $_GET["username"];
    $password = $_GET["password"];';
    $ast = $this->parser->parse($code);

    $candidate = new FunctionCandidate(
        nodes: $ast,
        effectType: SideEffectType::EXTERNAL_STATE,
        size: 2,
        complexity: 4,
        usedVariables: [],
        definedVariables: ['$username', '$password'],
        startLine: 1,
        endLine: 3
    );

    $result = $this->extractor->extract($candidate);

    $comments = $result->function->getAttribute('comments');
    $docComment = $comments[0]->getText();
    expect($docComment)->toContain('External state access');
});

it('handles database operations', function () {
    $candidate = new FunctionCandidate(
        nodes: [],
        effectType: SideEffectType::DATABASE,
        size: 3,
        complexity: 6,
        usedVariables: ['$connection', '$query'],
        definedVariables: [],
    );

    $result = $this->extractor->extract($candidate);

    $comments = $result->function->getAttribute('comments');
    $docComment = $comments[0]->getText();
    expect($docComment)->toContain('Database operations');
});

it('handles HTTP operations', function () {
    $candidate = new FunctionCandidate(
        nodes: [],
        effectType: SideEffectType::HTTP,
        size: 4,
        complexity: 7,
        usedVariables: ['$url'],
        definedVariables: [],
    );

    $result = $this->extractor->extract($candidate);

    $comments = $result->function->getAttribute('comments');
    $docComment = $comments[0]->getText();
    expect($docComment)->toContain('HTTP requests');
});

it('handles non-deterministic operations', function () {
    $candidate = new FunctionCandidate(
        nodes: [],
        effectType: SideEffectType::NON_DETERMINISTIC,
        size: 2,
        complexity: 3,
        usedVariables: [],
        definedVariables: ['$random'],
    );

    $result = $this->extractor->extract($candidate);

    $comments = $result->function->getAttribute('comments');
    $docComment = $comments[0]->getText();
    expect($docComment)->toContain('Non-deterministic operations');
});

it('excludes defined variables from function parameters', function () {
    $candidate = new FunctionCandidate(
        nodes: [],
        effectType: SideEffectType::PURE,
        size: 3,
        complexity: 5,
        usedVariables: ['$a', '$b', '$c'],
        definedVariables: ['$c', '$d'],
    );

    $result = $this->extractor->extract($candidate);

    // Only $a and $b should be parameters (not $c, as it's defined)
    expect($result->parameters)->toHaveCount(2);
    expect($result->parameters)->toContain('$a', '$b');
    expect($result->parameters)->not->toContain('$c');
});

it('creates return statement only when returnVariable is set', function () {
    $code = '<?php $a = 1;';
    $ast = $this->parser->parse($code);

    $candidateWithReturn = new FunctionCandidate(
        nodes: $ast,
        effectType: SideEffectType::PURE,
        size: 1,
        complexity: 2,
        usedVariables: [],
        definedVariables: ['$a'],
        returnVariable: '$a',
    );

    $candidateWithoutReturn = new FunctionCandidate(
        nodes: $ast,
        effectType: SideEffectType::IO,
        size: 1,
        complexity: 2,
        usedVariables: [],
        definedVariables: [],
        returnVariable: null,
    );

    $resultWithReturn = $this->extractor->extract($candidateWithReturn);
    $resultWithoutReturn = $this->extractor->extract($candidateWithoutReturn);

    expect($resultWithReturn->hasReturn())->toBeTrue();
    expect($resultWithoutReturn->hasReturn())->toBeFalse();
});

it('preserves original line information in doc comment', function () {
    $candidate = new FunctionCandidate(
        nodes: [],
        effectType: SideEffectType::PURE,
        size: 5,
        complexity: 10,
        usedVariables: [],
        definedVariables: [],
        startLine: 42,
        endLine: 47
    );

    $result = $this->extractor->extract($candidate);

    $comments = $result->function->getAttribute('comments');
    $docComment = $comments[0]->getText();
    expect($docComment)->toContain('Original lines: 42-47');
});
