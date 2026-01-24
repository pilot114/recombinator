<?php

use PhpParser\ParserFactory;
use Recombinator\Domain\ComplexityMetrics;
use Recombinator\Domain\ComplexityComparison;

beforeEach(
    function (): void {
        $this->parser = new ParserFactory()->createForHostVersion();
    }
);

test(
    'creates metrics from simple code',
    function (): void {
        $code = '<?php
        $x = 1 + 2;
    ';
        $ast = $this->parser->parse($code);

        $metrics = ComplexityMetrics::fromNodes($ast);

        expect($metrics->getCognitiveComplexity())->toBeGreaterThanOrEqual(0);
        expect($metrics->getCyclomaticComplexity())->toBeGreaterThanOrEqual(1);
    }
);

test(
    'creates metrics with name',
    function (): void {
        $code = '<?php
        if ($x > 0) {
            echo "positive";
        }
    ';
        $ast = $this->parser->parse($code);

        $metrics = ComplexityMetrics::fromNodes($ast, 'testFunction');

        expect($metrics->getName())->toBe('testFunction');
    }
);

test(
    'gets cognitive complexity',
    function (): void {
        $code = '<?php
        $result = $x + $y;
    ';
        $ast = $this->parser->parse($code);

        $metrics = ComplexityMetrics::fromNodes($ast);

        expect($metrics->getCognitiveComplexity())->toBeInt();
    }
);

test(
    'gets cyclomatic complexity',
    function (): void {
        $code = '<?php
        if ($x > 0) {
            echo "positive";
        }
    ';
        $ast = $this->parser->parse($code);

        $metrics = ComplexityMetrics::fromNodes($ast);

        expect($metrics->getCyclomaticComplexity())->toBe(2); // Base 1 + if 1
    }
);

test(
    'gets lines of code',
    function (): void {
        $code = '<?php
        $x = 1;
        $y = 2;
        $z = 3;
    ';
        $ast = $this->parser->parse($code);

        $metrics = ComplexityMetrics::fromNodes($ast);

        expect($metrics->getLinesOfCode())->toBeGreaterThan(0);
    }
);

test(
    'gets nesting depth',
    function (): void {
        $code = '<?php
        if ($x > 0) {
            if ($y > 0) {
                echo "both positive";
            }
        }
    ';
        $ast = $this->parser->parse($code);

        $metrics = ComplexityMetrics::fromNodes($ast);

        expect($metrics->getNestingDepth())->toBe(2);
    }
);

test(
    'compares metrics',
    function (): void {
        $code1 = '<?php $x = 1;';
        $code2 = '<?php if ($x > 0) { echo $x; }';

        $ast1 = $this->parser->parse($code1);
        $ast2 = $this->parser->parse($code2);

        $metrics1 = ComplexityMetrics::fromNodes($ast1);
        $metrics2 = ComplexityMetrics::fromNodes($ast2);

        $comparison = $metrics1->compareTo($metrics2);

        expect($comparison)->toBeInstanceOf(ComplexityComparison::class);
    }
);

test(
    'checks if improved',
    function (): void {
        $code1 = '<?php
        if ($x > 0) {
            if ($y > 0) {
                echo "both";
            }
        }
    ';
        $code2 = '<?php
        if ($x > 0 && $y > 0) {
            echo "both";
        }
    ';

        $ast1 = $this->parser->parse($code1);
        $ast2 = $this->parser->parse($code2);

        $before = ComplexityMetrics::fromNodes($ast1);
        $after = ComplexityMetrics::fromNodes($ast2);

        // После оптимизации сложность может снизиться
        expect($after->getCyclomaticComplexity())->toBeLessThanOrEqual($before->getCyclomaticComplexity());
    }
);

test(
    'checks if worse',
    function (): void {
        $simpleCode = '<?php $x = 1;';
        $complexCode = '<?php
        if ($x > 0) {
            for ($i = 0; $i < 10; $i++) {
                echo $i;
            }
        }
    ';

        $simpleAst = $this->parser->parse($simpleCode);
        $complexAst = $this->parser->parse($complexCode);

        $simple = ComplexityMetrics::fromNodes($simpleAst);
        $complex = ComplexityMetrics::fromNodes($complexAst);

        expect($complex->isWorseComparedTo($simple))->toBeTrue();
    }
);

test(
    'calculates overall complexity',
    function (): void {
        $code = '<?php
        if ($x > 0) {
            echo "positive";
        }
    ';
        $ast = $this->parser->parse($code);

        $metrics = ComplexityMetrics::fromNodes($ast);

        $overall = $metrics->getOverallComplexity();

        expect($overall)->toBeFloat();
        expect($overall)->toBeGreaterThan(0);
    }
);

test(
    'gets cognitive complexity level',
    function (): void {
        $simpleCode = '<?php $x = 1;';
        $ast = $this->parser->parse($simpleCode);

        $metrics = ComplexityMetrics::fromNodes($ast);

        $level = $metrics->getCognitiveComplexityLevel();

        expect($level)->toBeIn(['simple', 'medium', 'complex']);
    }
);

test(
    'gets cyclomatic complexity level',
    function (): void {
        $code = '<?php
        if ($x > 0) {
            echo "positive";
        }
    ';
        $ast = $this->parser->parse($code);

        $metrics = ComplexityMetrics::fromNodes($ast);

        $level = $metrics->getCyclomaticComplexityLevel();

        expect($level)->toBeIn(['simple', 'moderate', 'complex', 'very_complex']);
    }
);

test(
    'formats metrics',
    function (): void {
        $code = '<?php
        if ($x > 0) {
            echo "positive";
        }
    ';
        $ast = $this->parser->parse($code);

        $metrics = ComplexityMetrics::fromNodes($ast, 'testFunc');

        $formatted = $metrics->format();

        expect($formatted)->toContain('testFunc');
        expect($formatted)->toContain('Cognitive');
        expect($formatted)->toContain('Cyclomatic');
    }
);

// ComplexityComparison tests

test(
    'gets cognitive delta',
    function (): void {
        $code1 = '<?php $x = 1;';
        $code2 = '<?php if ($x > 0) { echo $x; }';

        $ast1 = $this->parser->parse($code1);
        $ast2 = $this->parser->parse($code2);

        $before = ComplexityMetrics::fromNodes($ast1);
        $after = ComplexityMetrics::fromNodes($ast2);

        $comparison = $before->compareTo($after);

        expect($comparison->getCognitiveDelta())->toBeInt();
    }
);

test(
    'gets cyclomatic delta',
    function (): void {
        $code1 = '<?php $x = 1;';
        $code2 = '<?php if ($x > 0) { echo $x; }';

        $ast1 = $this->parser->parse($code1);
        $ast2 = $this->parser->parse($code2);

        $before = ComplexityMetrics::fromNodes($ast1);
        $after = ComplexityMetrics::fromNodes($ast2);

        $comparison = $before->compareTo($after);

        expect($comparison->getCyclomaticDelta())->toBeGreaterThan(0);
    }
);

test(
    'calculates cognitive improvement percentage',
    function (): void {
        $complexCode = '<?php
        if ($x > 0) {
            if ($y > 0) {
                echo "both";
            }
        }
    ';
        $simpleCode = '<?php
        echo "test";
    ';

        $complexAst = $this->parser->parse($complexCode);
        $simpleAst = $this->parser->parse($simpleCode);

        $before = ComplexityMetrics::fromNodes($complexAst);
        $after = ComplexityMetrics::fromNodes($simpleAst);

        $comparison = $before->compareTo($after);

        // Improvement should be positive when complexity decreases
        expect($comparison->getCognitiveImprovement())->toBeGreaterThan(0);
    }
);

test(
    'calculates cyclomatic improvement percentage',
    function (): void {
        $complexCode = '<?php
        if ($x > 0) {
            for ($i = 0; $i < 10; $i++) {
                echo $i;
            }
        }
    ';
        $simpleCode = '<?php echo "test";';

        $complexAst = $this->parser->parse($complexCode);
        $simpleAst = $this->parser->parse($simpleCode);

        $before = ComplexityMetrics::fromNodes($complexAst);
        $after = ComplexityMetrics::fromNodes($simpleAst);

        $comparison = $before->compareTo($after);

        expect($comparison->getCyclomaticImprovement())->toBeGreaterThan(0);
    }
);

test(
    'comparison detects improvement',
    function (): void {
        $complexCode = '<?php
        if ($x > 0) {
            echo "positive";
        }
    ';
        $simpleCode = '<?php echo "test";';

        $complexAst = $this->parser->parse($complexCode);
        $simpleAst = $this->parser->parse($simpleCode);

        $before = ComplexityMetrics::fromNodes($complexAst);
        $after = ComplexityMetrics::fromNodes($simpleAst);

        $comparison = $before->compareTo($after);

        expect($comparison->isImproved())->toBeTrue();
    }
);

test(
    'comparison detects worsening',
    function (): void {
        $simpleCode = '<?php echo "test";';
        $complexCode = '<?php
        if ($x > 0) {
            for ($i = 0; $i < 10; $i++) {
                echo $i;
            }
        }
    ';

        $simpleAst = $this->parser->parse($simpleCode);
        $complexAst = $this->parser->parse($complexCode);

        $before = ComplexityMetrics::fromNodes($simpleAst);
        $after = ComplexityMetrics::fromNodes($complexAst);

        $comparison = $before->compareTo($after);

        expect($comparison->isWorse())->toBeTrue();
    }
);

test(
    'formats comparison',
    function (): void {
        $code1 = '<?php $x = 1;';
        $code2 = '<?php if ($x > 0) { echo $x; }';

        $ast1 = $this->parser->parse($code1);
        $ast2 = $this->parser->parse($code2);

        $before = ComplexityMetrics::fromNodes($ast1);
        $after = ComplexityMetrics::fromNodes($ast2);

        $comparison = $before->compareTo($after);
        $formatted = $comparison->format();

        expect($formatted)->toContain('Cognitive');
        expect($formatted)->toContain('Cyclomatic');
        expect($formatted)->toContain('→');
    }
);

test(
    'handles zero complexity improvement',
    function (): void {
        $code = '<?php $x = 1;';
        $ast = $this->parser->parse($code);

        $metrics1 = ComplexityMetrics::fromNodes($ast);
        $metrics2 = ComplexityMetrics::fromNodes($ast);

        $comparison = $metrics1->compareTo($metrics2);

        expect($comparison->getCognitiveDelta())->toBe(0);
        expect($comparison->getCyclomaticDelta())->toBe(0);
    }
);
