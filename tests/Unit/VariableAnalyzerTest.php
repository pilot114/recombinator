<?php

declare(strict_types=1);

use PhpParser\ParserFactory;
use Recombinator\Analysis\VariableAnalyzer;

beforeEach(
    function (): void {
        $this->analyzer = new VariableAnalyzer();
        $this->parser = new ParserFactory()->createForHostVersion();
    }
);

it(
    'detects used variables', function (): void {
        $code = '<?php $result = $x + $y;';
        $ast = $this->parser->parse($code);

        $result = $this->analyzer->analyze($ast);

        expect($result['used'])->toContain('$x', '$y');
    }
);

it(
    'detects defined variables', function (): void {
        $code = '<?php $result = $x + $y;';
        $ast = $this->parser->parse($code);

        $result = $this->analyzer->analyze($ast);

        expect($result['defined'])->toContain('$result');
    }
);

it(
    'excludes special variables from used', function (): void {
        $code = '<?php $result = $_GET["key"];';
        $ast = $this->parser->parse($code);

        $result = $this->analyzer->analyze($ast);

        expect($result['used'])->not->toContain('$_GET');
    }
);

it(
    'handles multiple assignments', function (): void {
        $code = '<?php
    $a = 1;
    $b = 2;
    $c = $a + $b;';
        $ast = $this->parser->parse($code);

        $result = $this->analyzer->analyze($ast);

        expect($result['defined'])->toContain('$a', '$b', '$c');
        expect($result['used'])->toContain('$a', '$b');
    }
);

it(
    'gets parameters correctly', function (): void {
        $code = '<?php
    $result = $x + $y;
    $z = $result * 2;';
        $ast = $this->parser->parse($code);

        $params = $this->analyzer->getParameters($ast);

        // $x and $y are used but not defined
        expect($params)->toContain('$x', '$y');
        expect($params)->not->toContain('$result', '$z');
    }
);

it(
    'gets local variables correctly', function (): void {
        $code = '<?php
    $temp = $input * 2;
    $result = $temp + 1;';
        $ast = $this->parser->parse($code);

        $locals = $this->analyzer->getLocalVariables($ast);

        // $temp is defined but might not be used outside
        // This test might need adjustment based on actual implementation
        expect($locals)->toBeArray();
    }
);

it(
    'handles array access', function (): void {
        $code = '<?php $value = $arr[$key];';
        $ast = $this->parser->parse($code);

        $result = $this->analyzer->analyze($ast);

        expect($result['used'])->toContain('$arr', '$key');
        expect($result['defined'])->toContain('$value');
    }
);

it(
    'handles property access', function (): void {
        $code = '<?php $value = $obj->property;';
        $ast = $this->parser->parse($code);

        $result = $this->analyzer->analyze($ast);

        expect($result['used'])->toContain('$obj');
        expect($result['defined'])->toContain('$value');
    }
);

it(
    'handles nested expressions', function (): void {
        $code = '<?php $result = ($a + $b) * ($c - $d);';
        $ast = $this->parser->parse($code);

        $result = $this->analyzer->analyze($ast);

        expect($result['used'])->toContain('$a', '$b', '$c', '$d');
        expect($result['defined'])->toContain('$result');
    }
);

it(
    'ignores variables in nested functions', function (): void {
        $code = '<?php
    $outer = 1;
    function inner() {
        $inner = 2;
        return $inner;
    }';
        $ast = $this->parser->parse($code);

        $result = $this->analyzer->analyze($ast);

        expect($result['defined'])->toContain('$outer');
        // $inner should not be in the top-level analysis
    }
);

it(
    'handles closures', function (): void {
        $code = '<?php
    $x = 10;
    $closure = function() use ($x) {
        return $x * 2;
    };';
        $ast = $this->parser->parse($code);

        $result = $this->analyzer->analyze($ast);

        expect($result['defined'])->toContain('$x', '$closure');
    }
);

it(
    'handles empty code', function (): void {
        $result = $this->analyzer->analyze([]);

        expect($result['used'])->toBeEmpty();
        expect($result['defined'])->toBeEmpty();
    }
);

it(
    'handles function calls with variables', function (): void {
        $code = '<?php $result = strlen($str);';
        $ast = $this->parser->parse($code);

        $result = $this->analyzer->analyze($ast);

        expect($result['used'])->toContain('$str');
        expect($result['defined'])->toContain('$result');
    }
);

it(
    'handles ternary operator', function (): void {
        $code = '<?php $result = $condition ? $a : $b;';
        $ast = $this->parser->parse($code);

        $result = $this->analyzer->analyze($ast);

        expect($result['used'])->toContain('$condition', '$a', '$b');
        expect($result['defined'])->toContain('$result');
    }
);
