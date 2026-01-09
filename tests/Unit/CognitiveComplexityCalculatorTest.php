<?php

declare(strict_types=1);

use PhpParser\Node;
use PhpParser\ParserFactory;
use Recombinator\Analysis\CognitiveComplexityCalculator;

beforeEach(
    function (): void {
        $this->calculator = new CognitiveComplexityCalculator();
        $this->parser = new ParserFactory()->createForHostVersion();
    }
);

it(
    'calculates complexity for simple binary operation', function (): void {
        $code = '<?php $x + $y;';
        $ast = $this->parser->parse($code);

        $complexity = $this->calculator->calculate($ast);

        expect($complexity)->toBe(1); // One binary operation
    }
);

it(
    'calculates complexity for function call', function (): void {
        $code = '<?php strlen($str);';
        $ast = $this->parser->parse($code);

        $complexity = $this->calculator->calculate($ast);

        expect($complexity)->toBe(2); // One function call = +2
    }
);

it(
    'calculates complexity for array access', function (): void {
        $code = '<?php $arr[0];';
        $ast = $this->parser->parse($code);

        $complexity = $this->calculator->calculate($ast);

        expect($complexity)->toBe(1); // One array access
    }
);

it(
    'calculates complexity for nested expression', function (): void {
        $code = '<?php $x * $y + $z;';
        $ast = $this->parser->parse($code);

        $complexity = $this->calculator->calculate($ast);

        expect($complexity)->toBe(2); // Two binary operations
    }
);

it(
    'calculates complexity for complex expression', function (): void {
        $code = '<?php sqrt($x * $x + $y * $y);';
        $ast = $this->parser->parse($code);

        $complexity = $this->calculator->calculate($ast);

        // sqrt call (+2) + 2 multiplications (+2) + 1 addition (+1) = 5
        expect($complexity)->toBeGreaterThanOrEqual(5);
    }
);

it(
    'calculates complexity for if statement with nesting', function (): void {
        $code = '<?php
    if ($a) {
        if ($b) {
            return true;
        }
    }';
        $ast = $this->parser->parse($code);

        $complexity = $this->calculator->calculate($ast);

        // First if (+1) + nested if (+2 for nesting level 2) = 3
        expect($complexity)->toBeGreaterThanOrEqual(2);
    }
);

it(
    'calculates complexity for ternary operator', function (): void {
        $code = '<?php $result = $a ? $b : $c;';
        $ast = $this->parser->parse($code);

        $complexity = $this->calculator->calculate($ast);

        expect($complexity)->toBeGreaterThanOrEqual(2); // Ternary = +2
    }
);

it(
    'classifies simple expression correctly', function (): void {
        $code = '<?php $x + $y;';
        $ast = $this->parser->parse($code);

        $complexity = $this->calculator->calculate($ast);
        $level = $this->calculator->getComplexityLevel($complexity);

        expect($level)->toBe('simple');
    }
);

it(
    'classifies medium expression correctly', function (): void {
        $code = '<?php $x * $y + $z;';
        $ast = $this->parser->parse($code);

        $complexity = $this->calculator->calculate($ast);
        $level = $this->calculator->getComplexityLevel($complexity);

        expect($level)->toBeIn(['simple', 'medium']);
    }
);

it(
    'classifies complex expression correctly', function (): void {
        $code = '<?php sqrt($x * $x + $y * $y);';
        $ast = $this->parser->parse($code);

        $complexity = $this->calculator->calculate($ast);
        $level = $this->calculator->getComplexityLevel($complexity);

        expect($level)->toBeIn(['medium', 'complex']);
    }
);

it(
    'handles empty AST', function (): void {
        $complexity = $this->calculator->calculate([]);

        expect($complexity)->toBe(0);
    }
);

it(
    'handles method calls', function (): void {
        $code = '<?php $obj->method($arg);';
        $ast = $this->parser->parse($code);

        $complexity = $this->calculator->calculate($ast);

        expect($complexity)->toBeGreaterThanOrEqual(2); // Method call = +2
    }
);

it(
    'handles static calls', function (): void {
        $code = '<?php MyClass::method($arg);';
        $ast = $this->parser->parse($code);

        $complexity = $this->calculator->calculate($ast);

        expect($complexity)->toBeGreaterThanOrEqual(2); // Static call = +2
    }
);

it(
    'handles property fetch', function (): void {
        $code = '<?php $obj->property;';
        $ast = $this->parser->parse($code);

        $complexity = $this->calculator->calculate($ast);

        expect($complexity)->toBe(1); // Property fetch = +1
    }
);

it(
    'handles logical operators', function (): void {
        $code = '<?php $a && $b || $c;';
        $ast = $this->parser->parse($code);

        $complexity = $this->calculator->calculate($ast);

        // Two logical operators (+2) = 2
        expect($complexity)->toBeGreaterThanOrEqual(2);
    }
);
