<?php

use PhpParser\Node;
use PhpParser\ParserFactory;
use Recombinator\Analysis\CyclomaticComplexityCalculator;
use Recombinator\Contract\ComplexityCalculatorInterface;

beforeEach(
    function (): void {
        $this->parser = new ParserFactory()->createForHostVersion();
        $this->calculator = new CyclomaticComplexityCalculator();
    }
);

test(
    'implements ComplexityCalculatorInterface',
    function (): void {
        expect(new CyclomaticComplexityCalculator())->toBeInstanceOf(ComplexityCalculatorInterface::class);
    }
);

test(
    'calculates base complexity as 1 for empty code',
    function (): void {
        $code = '<?php ';
        $ast = $this->parser->parse($code);

        $complexity = $this->calculator->calculate($ast);

        expect($complexity)->toBe(1);
    }
);

test(
    'calculates complexity for if statement',
    function (): void {
        $code = '<?php
        if ($x > 0) {
            echo "positive";
        }
    ';
        $ast = $this->parser->parse($code);

        $complexity = $this->calculator->calculate($ast);

        // Base 1 + if (1) = 2
        expect($complexity)->toBe(2);
    }
);

test(
    'calculates complexity for if-elseif-else',
    function (): void {
        $code = '<?php
        if ($x > 0) {
            echo "positive";
        } elseif ($x < 0) {
            echo "negative";
        } else {
            echo "zero";
        }
    ';
        $ast = $this->parser->parse($code);

        $complexity = $this->calculator->calculate($ast);

        // Base 1 + if (1) + elseif (1) = 3
        expect($complexity)->toBe(3);
    }
);

test(
    'calculates complexity for while loop',
    function (): void {
        $code = '<?php
        while ($i < 10) {
            $i++;
        }
    ';
        $ast = $this->parser->parse($code);

        $complexity = $this->calculator->calculate($ast);

        // Base 1 + while (1) = 2
        expect($complexity)->toBe(2);
    }
);

test(
    'calculates complexity for for loop',
    function (): void {
        $code = '<?php
        for ($i = 0; $i < 10; $i++) {
            echo $i;
        }
    ';
        $ast = $this->parser->parse($code);

        $complexity = $this->calculator->calculate($ast);

        // Base 1 + for (1) = 2
        expect($complexity)->toBe(2);
    }
);

test(
    'calculates complexity for foreach loop',
    function (): void {
        $code = '<?php
        foreach ($arr as $item) {
            echo $item;
        }
    ';
        $ast = $this->parser->parse($code);

        $complexity = $this->calculator->calculate($ast);

        // Base 1 + foreach (1) = 2
        expect($complexity)->toBe(2);
    }
);

test(
    'calculates complexity for switch case',
    function (): void {
        $code = '<?php
        switch ($x) {
            case 1:
                echo "one";
                break;
            case 2:
                echo "two";
                break;
            default:
                echo "other";
        }
    ';
        $ast = $this->parser->parse($code);

        $complexity = $this->calculator->calculate($ast);

        // Base 1 + case 1 (1) + case 2 (1) = 3
        // default не добавляет сложности
        expect($complexity)->toBe(3);
    }
);

test(
    'calculates complexity for try-catch',
    function (): void {
        $code = '<?php
        try {
            throw new Exception();
        } catch (Exception $e) {
            echo "error";
        }
    ';
        $ast = $this->parser->parse($code);

        $complexity = $this->calculator->calculate($ast);

        // Base 1 + catch (1) = 2
        expect($complexity)->toBe(2);
    }
);

test(
    'calculates complexity for multiple catch blocks',
    function (): void {
        $code = '<?php
        try {
            throw new Exception();
        } catch (RuntimeException $e) {
            echo "runtime error";
        } catch (Exception $e) {
            echo "error";
        }
    ';
        $ast = $this->parser->parse($code);

        $complexity = $this->calculator->calculate($ast);

        // Base 1 + catch (1) + catch (1) = 3
        expect($complexity)->toBe(3);
    }
);

test(
    'calculates complexity for ternary operator',
    function (): void {
        $code = '<?php
        $result = $x > 0 ? "positive" : "non-positive";
    ';
        $ast = $this->parser->parse($code);

        $complexity = $this->calculator->calculate($ast);

        // Base 1 + ternary (1) = 2
        expect($complexity)->toBe(2);
    }
);

test(
    'calculates complexity for boolean AND',
    function (): void {
        $code = '<?php
        if ($x > 0 && $y > 0) {
            echo "both positive";
        }
    ';
        $ast = $this->parser->parse($code);

        $complexity = $this->calculator->calculate($ast);

        // Base 1 + if (1) + && (1) = 3
        expect($complexity)->toBe(3);
    }
);

test(
    'calculates complexity for boolean OR',
    function (): void {
        $code = '<?php
        if ($x > 0 || $y > 0) {
            echo "at least one positive";
        }
    ';
        $ast = $this->parser->parse($code);

        $complexity = $this->calculator->calculate($ast);

        // Base 1 + if (1) + || (1) = 3
        expect($complexity)->toBe(3);
    }
);

test(
    'calculates complexity for null coalescing operator',
    function (): void {
        $code = '<?php
        $result = $x ?? "default";
    ';
        $ast = $this->parser->parse($code);

        $complexity = $this->calculator->calculate($ast);

        // Base 1 + ?? (1) = 2
        expect($complexity)->toBe(2);
    }
);

test(
    'calculates complexity for match expression',
    function (): void {
        $code = '<?php
        $result = match($x) {
            1 => "one",
            2 => "two",
            default => "other"
        };
    ';
        $ast = $this->parser->parse($code);

        $complexity = $this->calculator->calculate($ast);

        // Base 1 + case 1 (1) + case 2 (1) = 3
        // default не добавляет сложности
        expect($complexity)->toBe(3);
    }
);

test(
    'calculates complexity for nested if statements',
    function (): void {
        $code = '<?php
        if ($x > 0) {
            if ($y > 0) {
                echo "both positive";
            }
        }
    ';
        $ast = $this->parser->parse($code);

        $complexity = $this->calculator->calculate($ast);

        // Base 1 + if (1) + if (1) = 3
        expect($complexity)->toBe(3);
    }
);

test(
    'calculates complexity for complex function',
    function (): void {
        $code = '<?php
        function complexFunction($x, $y) {
            if ($x > 0) {
                for ($i = 0; $i < 10; $i++) {
                    if ($i % 2 === 0 && $y > 0) {
                        echo $i;
                    }
                }
            } elseif ($x < 0) {
                while ($y > 0) {
                    $y--;
                }
            }
            return $x ?? 0;
        }
    ';
        $ast = $this->parser->parse($code);

        $complexity = $this->calculator->calculate($ast);

        // Base 1 + if (1) + for (1) + if (1) + && (1) + elseif (1) + while (1) + ?? (1) = 8
        expect($complexity)->toBe(8);
    }
);

test(
    'determines complexity level as simple',
    function (): void {
        expect($this->calculator->getComplexityLevel(5))->toBe('simple');
        expect($this->calculator->getComplexityLevel(10))->toBe('simple');
    }
);

test(
    'determines complexity level as moderate',
    function (): void {
        expect($this->calculator->getComplexityLevel(11))->toBe('moderate');
        expect($this->calculator->getComplexityLevel(20))->toBe('moderate');
    }
);

test(
    'determines complexity level as complex',
    function (): void {
        expect($this->calculator->getComplexityLevel(21))->toBe('complex');
        expect($this->calculator->getComplexityLevel(50))->toBe('complex');
    }
);

test(
    'determines complexity level as very complex',
    function (): void {
        expect($this->calculator->getComplexityLevel(51))->toBe('very_complex');
        expect($this->calculator->getComplexityLevel(100))->toBe('very_complex');
    }
);

test(
    'checks if complexity is acceptable',
    function (): void {
        expect($this->calculator->isAcceptable(5))->toBeTrue();
        expect($this->calculator->isAcceptable(10))->toBeTrue();
        expect($this->calculator->isAcceptable(11))->toBeFalse();
    }
);

test(
    'checks if complexity is acceptable with custom threshold',
    function (): void {
        expect($this->calculator->isAcceptable(15, 20))->toBeTrue();
        expect($this->calculator->isAcceptable(25, 20))->toBeFalse();
    }
);

test(
    'calculates average complexity',
    function (): void {
        $code = '<?php
        function simple() {
            return 1;
        }

        function withIf($x) {
            if ($x > 0) {
                return $x;
            }
            return 0;
        }

        function withLoop($n) {
            for ($i = 0; $i < $n; $i++) {
                echo $i;
            }
        }
    ';
        $ast = $this->parser->parse($code);

        // Извлекаем функции
        $functions = array_filter($ast, fn($node): bool => $node instanceof Node\Stmt\Function_);

        $average = $this->calculator->calculateAverage($functions);

        // simple: 1, withIf: 2, withLoop: 2 => average = (1+2+2)/3 = 1.67
        expect($average)->toBeGreaterThan(1.5);
        expect($average)->toBeLessThan(2.0);
    }
);

test(
    'calculates zero average for empty array',
    function (): void {
        $average = $this->calculator->calculateAverage([]);
        expect($average)->toBe(0.0);
    }
);
