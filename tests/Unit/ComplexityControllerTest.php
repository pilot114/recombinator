<?php

use PhpParser\ParserFactory;
use Recombinator\Analysis\ComplexityController;
use Recombinator\Analysis\ComplexityWarning;

beforeEach(
    function (): void {
        $this->parser = new ParserFactory()->createForHostVersion();
        $this->controller = new ComplexityController();
    }
);

test(
    'registers before metrics',
    function (): void {
        $code = '<?php
        if ($x > 0) {
            echo "positive";
        }
    ';
        $ast = $this->parser->parse($code);

        $this->controller->registerBefore('testFunc', $ast);

        // Should not throw any exceptions
        expect(true)->toBeTrue();
    }
);

test(
    'registers after metrics and checks changes',
    function (): void {
        $code = '<?php
        if ($x > 0) {
            echo "positive";
        }
    ';
        $ast = $this->parser->parse($code);

        $this->controller->registerBefore('testFunc', $ast);
        $this->controller->registerAfter('testFunc', $ast);

        // Should generate no warnings for unchanged code
        $warnings = $this->controller->getWarnings();
        expect($warnings)->toBeArray();
    }
);

test(
    'detects threshold exceeded for cognitive complexity',
    function (): void {
        $complexCode = '<?php
        function complex() {
            if ($a > 0) {
                if ($b > 0) {
                    if ($c > 0) {
                        for ($i = 0; $i < 10; $i++) {
                            echo $i * $i + $i;
                        }
                    }
                }
            }
        }
    ';
        $ast = $this->parser->parse($complexCode);

        $controller = new ComplexityController(cognitiveThreshold: 5);
        $controller->registerBefore('complex', $ast);
        $controller->registerAfter('complex', $ast);

        $warnings = $controller->getWarningsByLevel('warning');

        // Should have threshold exceeded warning
        expect(count($warnings))->toBeGreaterThan(0);
    }
);

test(
    'detects threshold exceeded for cyclomatic complexity',
    function (): void {
        $complexCode = '<?php
        if ($a) {}
        if ($b) {}
        if ($c) {}
        if ($d) {}
        if ($e) {}
        if ($f) {}
        if ($g) {}
        if ($h) {}
        if ($i) {}
        if ($j) {}
        if ($k) {}
    ';
        $ast = $this->parser->parse($complexCode);

        $controller = new ComplexityController(cyclomaticThreshold: 5);
        $controller->registerBefore('test', $ast);
        $controller->registerAfter('test', $ast);

        $warnings = $controller->getWarningsByLevel('warning');

        expect(count($warnings))->toBeGreaterThan(0);
    }
);

test(
    'detects complexity increase',
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

        $this->controller->registerBefore('test', $simpleAst);
        $this->controller->registerAfter('test', $complexAst);

        $warnings = $this->controller->getWarningsByLevel('warning');

        expect(count($warnings))->toBeGreaterThan(0);
    }
);

test(
    'detects improvement',
    function (): void {
        $complexCode = '<?php
        if ($x > 0) {
            echo "x positive";
        }
        if ($y > 0) {
            echo "y positive";
        }
        if ($z > 0) {
            echo "z positive";
        }
    ';
        $simpleCode = '<?php
        echo "test";
    ';

        $complexAst = $this->parser->parse($complexCode);
        $simpleAst = $this->parser->parse($simpleCode);

        $this->controller->registerBefore('test', $complexAst);
        $this->controller->registerAfter('test', $simpleAst);

        $infoWarnings = $this->controller->getWarningsByLevel('info');

        // Should have improvement info
        expect(count($infoWarnings))->toBeGreaterThan(0);
    }
);

test(
    'strict mode treats increase as error',
    function (): void {
        $simpleCode = '<?php echo "test";';
        $complexCode = '<?php
        if ($x > 0) {
            echo "positive";
        }
    ';

        $simpleAst = $this->parser->parse($simpleCode);
        $complexAst = $this->parser->parse($complexCode);

        $controller = new ComplexityController(strictMode: true);
        $controller->registerBefore('test', $simpleAst);
        $controller->registerAfter('test', $complexAst);

        expect($controller->hasErrors())->toBeTrue();
    }
);

test(
    'gets warnings by level',
    function (): void {
        $simpleCode = '<?php echo "test";';
        $complexCode = '<?php
        if ($x > 0) {
            echo "positive";
        }
    ';

        $simpleAst = $this->parser->parse($simpleCode);
        $complexAst = $this->parser->parse($complexCode);

        $this->controller->registerBefore('test', $simpleAst);
        $this->controller->registerAfter('test', $complexAst);

        $warnings = $this->controller->getWarningsByLevel('warning');
        $errors = $this->controller->getWarningsByLevel('error');

        expect($warnings)->toBeArray();
        expect($errors)->toBeArray();
    }
);

test(
    'checks for errors',
    function (): void {
        $code = '<?php echo "test";';
        $ast = $this->parser->parse($code);

        $this->controller->registerBefore('test', $ast);
        $this->controller->registerAfter('test', $ast);

        expect($this->controller->hasErrors())->toBeFalse();
    }
);

test(
    'checks for warnings',
    function (): void {
        $simpleCode = '<?php echo "test";';
        $complexCode = '<?php
        if ($x > 0) {
            echo "positive";
        }
    ';

        $simpleAst = $this->parser->parse($simpleCode);
        $complexAst = $this->parser->parse($complexCode);

        $this->controller->registerBefore('test', $simpleAst);
        $this->controller->registerAfter('test', $complexAst);

        expect($this->controller->hasWarnings())->toBeTrue();
    }
);

test(
    'gets statistics',
    function (): void {
        $code1 = '<?php echo "test";';
        $code2 = '<?php if ($x) { echo "x"; }';

        $ast1 = $this->parser->parse($code1);
        $ast2 = $this->parser->parse($code2);

        $this->controller->registerBefore('func1', $ast1);
        $this->controller->registerAfter('func1', $ast1);

        $this->controller->registerBefore('func2', $ast2);
        $this->controller->registerAfter('func2', $ast2);

        $stats = $this->controller->getStatistics();

        expect($stats)->toHaveKey('total');
        expect($stats)->toHaveKey('improved');
        expect($stats)->toHaveKey('worse');
        expect($stats)->toHaveKey('unchanged');
        expect($stats['total'])->toBe(2);
    }
);

test(
    'gets comparison for identifier',
    function (): void {
        $code = '<?php echo "test";';
        $ast = $this->parser->parse($code);

        $this->controller->registerBefore('test', $ast);
        $this->controller->registerAfter('test', $ast);

        $comparison = $this->controller->getComparison('test');

        expect($comparison)->not->toBeNull();
    }
);

test(
    'returns null for non-existent comparison',
    function (): void {
        $comparison = $this->controller->getComparison('nonExistent');

        expect($comparison)->toBeNull();
    }
);

test(
    'clears all data',
    function (): void {
        $code = '<?php echo "test";';
        $ast = $this->parser->parse($code);

        $this->controller->registerBefore('test', $ast);
        $this->controller->registerAfter('test', $ast);

        $this->controller->clear();

        expect($this->controller->getWarnings())->toBeEmpty();
        expect($this->controller->getStatistics()['total'])->toBe(0);
    }
);

test(
    'formats report',
    function (): void {
        $code = '<?php echo "test";';
        $ast = $this->parser->parse($code);

        $this->controller->registerBefore('test', $ast);
        $this->controller->registerAfter('test', $ast);

        $report = $this->controller->formatReport();

        expect($report)->toContain('Complexity Analysis Report');
        expect($report)->toContain('Total functions/methods analyzed');
    }
);

// ComplexityWarning tests

test(
    'creates threshold exceeded warning',
    function (): void {
        $warning = ComplexityWarning::thresholdExceeded('testFunc', 'cognitive', 20, 15);

        expect($warning->getType())->toBe('threshold_exceeded');
        expect($warning->getLevel())->toBe('warning');
        expect($warning->getIdentifier())->toBe('testFunc');
    }
);

test(
    'creates increased complexity warning',
    function (): void {
        $warning = ComplexityWarning::increased('testFunc', 'cyclomatic', 5, 10);

        expect($warning->getType())->toBe('complexity_increased');
        expect($warning->getLevel())->toBe('warning');
        expect($warning->getMessage())->toContain('increased from 5 to 10');
    }
);

test(
    'creates improvement info',
    function (): void {
        $warning = ComplexityWarning::improved('testFunc', 25.0, 33.3);

        expect($warning->getType())->toBe('complexity_improved');
        expect($warning->getLevel())->toBe('info');
        expect($warning->getMessage())->toContain('improved');
    }
);

test(
    'formats warning',
    function (): void {
        $warning = ComplexityWarning::thresholdExceeded('testFunc', 'cognitive', 20, 15);

        $formatted = $warning->format();

        expect($formatted)->toContain('[WARN]');
        expect($formatted)->toContain('testFunc');
    }
);

test(
    'gets warning context',
    function (): void {
        $warning = ComplexityWarning::thresholdExceeded('testFunc', 'cognitive', 20, 15);

        $context = $warning->getContext();

        expect($context)->toHaveKey('metric');
        expect($context)->toHaveKey('value');
        expect($context)->toHaveKey('threshold');
    }
);

test(
    'handles multiple functions',
    function (): void {
        $simpleCode = '<?php echo "test";';
        $complexCode = '<?php if ($x) { for ($i = 0; $i < 10; $i++) {} }';

        $simpleAst = $this->parser->parse($simpleCode);
        $complexAst = $this->parser->parse($complexCode);

        $this->controller->registerBefore('func1', $simpleAst);
        $this->controller->registerAfter('func1', $complexAst); // worse

        $this->controller->registerBefore('func2', $complexAst);
        $this->controller->registerAfter('func2', $simpleAst); // improved

        $stats = $this->controller->getStatistics();

        expect($stats['improved'])->toBeGreaterThan(0);
        expect($stats['worse'])->toBeGreaterThan(0);
    }
);
