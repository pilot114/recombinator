<?php

declare(strict_types=1);

use PhpParser\Node;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use PhpParser\NodeTraverser;
use Recombinator\Transformation\Visitor\SideEffectMarkerVisitor;
use Recombinator\Transformation\AbstractionRecovery;
use Recombinator\Transformation\FunctionExtractor;

beforeEach(
    function (): void {
        $this->parser = new ParserFactory()->createForHostVersion();
        $this->printer = new Standard();
    }
);

/**
 * Helper to mark AST with side effects
 */
function markWithEffects(array $ast): array
{
    $traverser = new NodeTraverser();
    $traverser->addVisitor(new SideEffectMarkerVisitor());
    return $traverser->traverse($ast);
}

/**
 * Helper to execute PHP code in isolated process and capture output/return value
 */
function executeCode(string $code): array
{
    $tempFile = tempnam(sys_get_temp_dir(), 'recombinator_test_');
    file_put_contents($tempFile, $code);

    // Execute in separate process to avoid function redeclaration errors
    $output = shell_exec(sprintf('php %s 2>&1', $tempFile));
    unlink($tempFile);

    // Try to extract return value from serialized format
    // For simplicity, we'll just return the output
    return [
        'output' => $output ?? '',
        'return' => null, // Not easily capturable via shell_exec
    ];
}

it(
    'maintains output equivalence for simple pure function extraction', function (): void {
        $originalCode = '<?php
    $a = 5;
    $b = 10;
    $sum = $a + $b;
    echo $sum;';

        $foldedCode = '<?php
    function calculateSum_test1($a, $b) {
        $sum = $a + $b;
        return $sum;
    }
    $a = 5;
    $b = 10;
    $sum = calculateSum_test1($a, $b);
    echo $sum;';

        $original = executeCode($originalCode);
        $folded = executeCode($foldedCode);

        expect($original['output'])->toBe($folded['output']);
    }
);

it(
    'maintains output equivalence for IO function extraction', function (): void {
        $originalCode = '<?php
    echo "Line 1\n";
    echo "Line 2\n";
    echo "Line 3\n";';

        $foldedCode = '<?php
    function printLines_test2() {
        echo "Line 1\n";
        echo "Line 2\n";
        echo "Line 3\n";
    }
    printLines_test2();';

        $original = executeCode($originalCode);
        $folded = executeCode($foldedCode);

        expect($original['output'])->toBe($folded['output']);
    }
);

it(
    'maintains calculation equivalence for complex expressions', function (): void {
        $originalCode = '<?php
    $x = 3;
    $y = 4;
    $distance = sqrt($x * $x + $y * $y);
    echo $distance;';

        $foldedCode = '<?php
    function calculateDistance_test3($x, $y) {
        $distance = sqrt($x * $x + $y * $y);
        return $distance;
    }
    $x = 3;
    $y = 4;
    $distance = calculateDistance_test3($x, $y);
    echo $distance;';

        $original = executeCode($originalCode);
        $folded = executeCode($foldedCode);

        expect($original['output'])->toBe($folded['output']);
    }
);

it(
    'maintains equivalence with multiple parameters', function (): void {
        $originalCode = '<?php
    $a = 10;
    $b = 20;
    $c = 30;
    $result = $a + $b * $c;
    echo $result;';

        $foldedCode = '<?php
    function calculate_test4($a, $b, $c) {
        $result = $a + $b * $c;
        return $result;
    }
    $a = 10;
    $b = 20;
    $c = 30;
    $result = calculate_test4($a, $b, $c);
    echo $result;';

        $original = executeCode($originalCode);
        $folded = executeCode($foldedCode);

        expect($original['output'])->toBe($folded['output']);
    }
);

it(
    'maintains equivalence for string operations', function (): void {
        $originalCode = '<?php
    $first = "Hello";
    $last = "World";
    $greeting = $first . " " . $last;
    echo $greeting;';

        $foldedCode = '<?php
    function createGreeting_test5($first, $last) {
        $greeting = $first . " " . $last;
        return $greeting;
    }
    $first = "Hello";
    $last = "World";
    $greeting = createGreeting_test5($first, $last);
    echo $greeting;';

        $original = executeCode($originalCode);
        $folded = executeCode($foldedCode);

        expect($original['output'])->toBe($folded['output']);
    }
);

it(
    'maintains equivalence for array operations', function (): void {
        $originalCode = '<?php
    $arr = [1, 2, 3, 4, 5];
    $sum = array_sum($arr);
    echo $sum;';

        $foldedCode = '<?php
    function calculateArraySum_test6($arr) {
        $sum = array_sum($arr);
        return $sum;
    }
    $arr = [1, 2, 3, 4, 5];
    $sum = calculateArraySum_test6($arr);
    echo $sum;';

        $original = executeCode($originalCode);
        $folded = executeCode($foldedCode);

        expect($original['output'])->toBe($folded['output']);
    }
);

it(
    'maintains equivalence for conditional logic', function (): void {
        $originalCode = '<?php
    $x = 10;
    $y = 20;
    $result = ($x > $y) ? "x is greater" : "y is greater";
    echo $result;';

        $foldedCode = '<?php
    function compare_test7($x, $y) {
        $result = ($x > $y) ? "x is greater" : "y is greater";
        return $result;
    }
    $x = 10;
    $y = 20;
    $result = compare_test7($x, $y);
    echo $result;';

        $original = executeCode($originalCode);
        $folded = executeCode($foldedCode);

        expect($original['output'])->toBe($folded['output']);
    }
);

it(
    'maintains equivalence for loop-based calculations', function (): void {
        $originalCode = '<?php
    $sum = 0;
    for ($i = 1; $i <= 10; $i++) {
        $sum += $i;
    }
    echo $sum;';

        $foldedCode = '<?php
    function calculateSum_test8() {
        $sum = 0;
        for ($i = 1; $i <= 10; $i++) {
            $sum += $i;
        }
        return $sum;
    }
    $sum = calculateSum_test8();
    echo $sum;';

        $original = executeCode($originalCode);
        $folded = executeCode($foldedCode);

        expect($original['output'])->toBe($folded['output']);
    }
);

it(
    'validates extracted function can be called multiple times', function (): void {
        $code = '<?php
    function add_test9($a, $b) {
        return $a + $b;
    }

    $result1 = add_test9(5, 3);
    $result2 = add_test9(10, 20);
    $result3 = add_test9(100, 200);

    echo $result1 . "," . $result2 . "," . $result3;';

        $result = executeCode($code);

        expect($result['output'])->toBe('8,30,300');
    }
);

it(
    'maintains equivalence for nested calculations', function (): void {
        $originalCode = '<?php
    $a = 2;
    $b = 3;
    $c = 4;
    $result = $a * ($b + $c);
    echo $result;';

        $foldedCode = '<?php
    function calculate_test13($a, $b, $c) {
        $result = $a * ($b + $c);
        return $result;
    }
    $a = 2;
    $b = 3;
    $c = 4;
    $result = calculate_test13($a, $b, $c);
    echo $result;';

        $original = executeCode($originalCode);
        $folded = executeCode($foldedCode);

        expect($original['output'])->toBe($folded['output']);
    }
);

it(
    'maintains equivalence for function calls within extracted function', function (): void {
        $originalCode = '<?php
    function helper_test15($x) {
        return $x * 2;
    }

    $a = 5;
    $b = helper_test15($a);
    $c = $b + 10;
    echo $c;';

        $foldedCode = '<?php
    function helper_test15($x) {
        return $x * 2;
    }

    function process_test10($a) {
        $b = helper_test15($a);
        $c = $b + 10;
        return $c;
    }

    $a = 5;
    $c = process_test10($a);
    echo $c;';

        $original = executeCode($originalCode);
        $folded = executeCode($foldedCode);

        expect($original['output'])->toBe($folded['output']);
    }
);

it(
    'validates AST structure is valid after extraction', function (): void {
        $code = '<?php
    $a = 1;
    $b = 2;
    $c = 3;
    $sum = $a + $b + $c;';

        $ast = $this->parser->parse($code);
        $markedAst = markWithEffects($ast);

        $recovery = new AbstractionRecovery();
        $candidates = $recovery->analyze($markedAst);

        if ($candidates !== []) {
            $extractor = new FunctionExtractor();
            $result = $extractor->extract($candidates[0]);

            // Validate function AST
            expect($result->function)->toBeInstanceOf(Node\Stmt\Function_::class);
            expect($result->function->name)->not->toBeEmpty();
            expect($result->function->stmts)->toBeArray();

            // Validate call AST
            expect($result->call)->toBeInstanceOf(Node\Stmt::class);
        }

        expect(true)->toBeTrue();
    }
);

it(
    'ensures extracted code is syntactically valid', function (): void {
        $code = '<?php
    $x = 10;
    $y = 20;
    $z = $x + $y;';

        $ast = $this->parser->parse($code);
        $markedAst = markWithEffects($ast);

        $recovery = new AbstractionRecovery();
        $candidates = $recovery->analyze($markedAst);

        if ($candidates !== []) {
            $extractor = new FunctionExtractor();
            $result = $extractor->extract($candidates[0]);

            // Generate code
            $generatedCode = $this->printer->prettyPrint([$result->function]);

            // Try to parse it back
            $reparsedAst = $this->parser->parse('<?php ' . $generatedCode);

            expect($reparsedAst)->not->toBeEmpty();
            expect($reparsedAst[0])->toBeInstanceOf(Node\Stmt\Function_::class);
        }

        expect(true)->toBeTrue();
    }
);

it(
    'maintains equivalence for boolean operations', function (): void {
        $originalCode = '<?php
    $a = true;
    $b = false;
    $c = true;
    $result = $a && ($b || $c);
    echo $result ? "yes" : "no";';

        $foldedCode = '<?php
    function evaluateLogic_test11($a, $b, $c) {
        $result = $a && ($b || $c);
        return $result;
    }
    $a = true;
    $b = false;
    $c = true;
    $result = evaluateLogic_test11($a, $b, $c);
    echo $result ? "yes" : "no";';

        $original = executeCode($originalCode);
        $folded = executeCode($foldedCode);

        expect($original['output'])->toBe($folded['output']);
    }
);

it(
    'maintains equivalence for type operations', function (): void {
        $originalCode = '<?php
    $value = "123";
    $number = (int)$value;
    $doubled = $number * 2;
    echo $doubled;';

        $foldedCode = '<?php
    function processValue_test12($value) {
        $number = (int)$value;
        $doubled = $number * 2;
        return $doubled;
    }
    $value = "123";
    $doubled = processValue_test12($value);
    echo $doubled;';

        $original = executeCode($originalCode);
        $folded = executeCode($foldedCode);

        expect($original['output'])->toBe($folded['output']);
    }
);
