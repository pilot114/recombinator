<?php

declare(strict_types=1);

use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use Recombinator\Transformation\NestedConditionSimplifier;

beforeEach(
    function (): void {
        $this->simplifier = new NestedConditionSimplifier();
        $this->parser = new ParserFactory()->createForHostVersion();
        $this->printer = new Standard();
    }
);

it(
    'simplifies nested if conditions',
    function (): void {
        $code = '<?php
    if ($a) {
        if ($b) {
            return true;
        }
    }';
        $ast = $this->parser->parse($code);

        $simplified = $this->simplifier->simplify($ast);
        $result = $this->printer->prettyPrintFile($simplified);

        // Should combine into $a && $b
        expect($result)->toContain('&&');
    }
);

it(
    'analyzes nesting complexity',
    function (): void {
        $code = '<?php
    if ($a) {
        if ($b) {
            if ($c) {
                return true;
            }
        }
    }';
        $ast = $this->parser->parse($code);

        $analysis = $this->simplifier->analyze($ast);

        expect($analysis->maxNesting)->toBeGreaterThanOrEqual(3);
    }
);

it(
    'detects issues with deep nesting',
    function (): void {
        $code = '<?php
    if ($a) {
        if ($b) {
            if ($c) {
                if ($d) {
                    return true;
                }
            }
        }
    }';
        $ast = $this->parser->parse($code);

        $analysis = $this->simplifier->analyze($ast);

        expect($analysis->hasIssues(3))->toBeTrue();
    }
);

it(
    'does not detect issues with shallow nesting',
    function (): void {
        $code = '<?php
    if ($a) {
        return true;
    }';
        $ast = $this->parser->parse($code);

        $analysis = $this->simplifier->analyze($ast);

        expect($analysis->hasIssues(3))->toBeFalse();
    }
);

it(
    'counts complex nodes correctly',
    function (): void {
        $code = '<?php
    if ($a) {
        if ($b) {
            if ($c) {
                if ($d) {
                    return true;
                }
            }
        }
    }';
        $ast = $this->parser->parse($code);

        $analysis = $this->simplifier->analyze($ast);

        expect($analysis->getComplexNodeCount())->toBeGreaterThan(0);
    }
);

it(
    'generates nesting report',
    function (): void {
        $code = '<?php
    if ($a) {
        if ($b) {
            return true;
        }
    }';
        $ast = $this->parser->parse($code);

        $analysis = $this->simplifier->analyze($ast);
        $report = $analysis->getReport();

        expect($report)->toContain('Nesting Analysis');
        expect($report)->toContain('Max nesting level');
    }
);

it(
    'handles code without nesting',
    function (): void {
        $code = '<?php
    $x = 1;
    $y = 2;
    $z = $x + $y;';
        $ast = $this->parser->parse($code);

        $analysis = $this->simplifier->analyze($ast);

        expect($analysis->maxNesting)->toBe(0);
    }
);

it(
    'handles multiple if statements at same level',
    function (): void {
        $code = '<?php
    if ($a) {
        echo "a";
    }
    if ($b) {
        echo "b";
    }';
        $ast = $this->parser->parse($code);

        $analysis = $this->simplifier->analyze($ast);

        expect($analysis->maxNesting)->toBe(1);
    }
);

it(
    'analyzes average nesting',
    function (): void {
        $code = '<?php
    if ($a) {
        if ($b) {
            echo "nested";
        }
    }
    if ($c) {
        echo "single";
    }';
        $ast = $this->parser->parse($code);

        $analysis = $this->simplifier->analyze($ast);

        expect($analysis->avgNesting)->toBeGreaterThan(0);
    }
);

it(
    'does not modify simple code',
    function (): void {
        $code = '<?php
    if ($a) {
        return true;
    }';
        $ast = $this->parser->parse($code);
        $originalCode = $this->printer->prettyPrintFile($ast);

        $simplified = $this->simplifier->simplify($ast);
        $simplifiedCode = $this->printer->prettyPrintFile($simplified);

        // Should be roughly the same (might have minor formatting differences)
        expect(strlen((string) $simplifiedCode))->toBeLessThanOrEqual(strlen((string) $originalCode) + 50);
    }
);
