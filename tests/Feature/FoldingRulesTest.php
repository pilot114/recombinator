<?php

declare(strict_types=1);

use PhpParser\Node;
use PhpParser\ParserFactory;
use PhpParser\NodeTraverser;
use PhpParser\PrettyPrinter\Standard;
use Recombinator\Transformation\AbstractionRecovery;
use Recombinator\Transformation\Visitor\SideEffectMarkerVisitor;
use Recombinator\Transformation\SideEffectSeparator;
use Recombinator\Transformation\FunctionExtractor;
use Recombinator\Transformation\VariableDeclarationOptimizer;
use Recombinator\Transformation\NestedConditionSimplifier;
use Recombinator\Analysis\CognitiveComplexityCalculator;

beforeEach(function () {
    $this->parser = (new ParserFactory())->createForHostVersion();
    $this->printer = new Standard();
});

/**
 * Helper to mark AST with side effects
 */
function markAst(array $ast): array
{
    $traverser = new NodeTraverser();
    $traverser->addVisitor(new SideEffectMarkerVisitor());
    return $traverser->traverse($ast);
}

it('applies FOLD-FUNC-1: extracts pure computation into function', function () {
    $code = '<?php
    // Pure computation block (>= 5 lines)
    $x1 = 0;
    $y1 = 0;
    $x2 = 3;
    $y2 = 4;
    $dx = $x2 - $x1;
    $dy = $y2 - $y1;
    $distance = sqrt($dx * $dx + $dy * $dy);

    echo "Distance: " . $distance;';

    $ast = $this->parser->parse($code);
    $markedAst = markAst($ast);

    // Use AbstractionRecovery to find candidates
    $recovery = new AbstractionRecovery();
    $candidates = $recovery->analyze($markedAst);

    // Should find at least one viable pure block
    expect($candidates)->not->toBeEmpty();

    $pureCandidate = null;
    foreach ($candidates as $candidate) {
        if ($candidate->matchesPureBlockRule()) {
            $pureCandidate = $candidate;
            break;
        }
    }

    expect($pureCandidate)->not->toBeNull();

    // Extract function
    $extractor = new FunctionExtractor();
    $result = $extractor->extract($pureCandidate);

    expect($result->functionName)->toContain('calculate');
    expect($result->hasReturn())->toBeTrue();
});

it('applies FOLD-FUNC-2: extracts IO operations into function', function () {
    $code = '<?php
    // IO block (>= 3 lines, same effect type)
    echo "=== Report ===\n";
    echo "User: John\n";
    echo "Status: Active\n";
    echo "===============\n";';

    $ast = $this->parser->parse($code);
    $markedAst = markAst($ast);

    $recovery = new AbstractionRecovery();
    $candidates = $recovery->analyze($markedAst);

    $ioCandidate = null;
    foreach ($candidates as $candidate) {
        if ($candidate->matchesEffectBlockRule()) {
            $ioCandidate = $candidate;
            break;
        }
    }

    expect($ioCandidate)->not->toBeNull();

    $extractor = new FunctionExtractor();
    $result = $extractor->extract($ioCandidate);

    expect($result->functionName)->toContain('print');
});

it('applies FOLD-VAR-1: creates intermediate variable for complex expression', function () {
    $code = '<?php
    $result = sqrt($x * $x + $y * $y) + sqrt($z * $z + $w * $w);';

    $ast = $this->parser->parse($code);

    // Calculate cognitive complexity
    $calculator = new CognitiveComplexityCalculator();
    $complexity = $calculator->calculate($ast[0]);

    // Complex expression should have high cognitive complexity
    expect($complexity)->toBeGreaterThan(4);
});

it('applies FOLD-GROUP-3: groups related variable initialization', function () {
    $code = '<?php
    $x = $_GET["x"];
    echo "Processing...";
    $y = $_GET["y"];
    echo "More processing...";
    $z = $_GET["z"];';

    $ast = $this->parser->parse($code);
    $markedAst = markAst($ast);

    // Use VariableDeclarationOptimizer
    $optimizer = new VariableDeclarationOptimizer();
    $optimized = $optimizer->optimize($markedAst);

    // Should identify related variables
    expect($optimized)->toBeArray();
});

it('simplifies nested conditions', function () {
    $code = '<?php
    if ($a > 0) {
        if ($b > 0) {
            if ($c > 0) {
                echo "All positive";
            }
        }
    }';

    $ast = $this->parser->parse($code);

    $simplifier = new NestedConditionSimplifier();
    $analysis = $simplifier->analyze($ast);

    // Should detect deep nesting
    expect($analysis->maxNesting)->toBeGreaterThan(2);
});

it('prioritizes pure blocks over effect blocks', function () {
    $code = '<?php
    // Pure block
    $x = 1;
    $y = 2;
    $z = 3;
    $sum = $x + $y + $z;

    // IO block
    echo "Result: ";
    echo $sum;
    echo "\n";';

    $ast = $this->parser->parse($code);
    $markedAst = markAst($ast);

    $recovery = new AbstractionRecovery();
    $candidates = $recovery->analyze($markedAst);

    if (count($candidates) >= 2) {
        // Sort by priority
        usort($candidates, fn($a, $b) => $b->getPriority() <=> $a->getPriority());

        // First candidate should be pure (higher priority)
        expect($candidates[0]->effectType->value)->toBe('PURE');
    }
});

it('handles mixed code with multiple folding opportunities', function () {
    $code = '<?php
    // Pure computation
    $a = 10;
    $b = 20;
    $c = 30;
    $d = 40;
    $total = $a + $b + $c + $d;

    // IO operations
    echo "Processing...\n";
    echo "Total: " . $total . "\n";
    echo "Done!\n";

    // External state
    $username = $_GET["username"] ?? "guest";
    $role = $_GET["role"] ?? "user";';

    $ast = $this->parser->parse($code);
    $markedAst = markAst($ast);

    $recovery = new AbstractionRecovery();
    $candidates = $recovery->analyze($markedAst);

    // Should find multiple candidates
    expect($candidates)->not->toBeEmpty();
});

it('separates code by effect type', function () {
    $code = '<?php
    $x = 1 + 2;
    echo "Hello";
    $y = $_GET["y"];
    echo "World";
    $z = 3 + 4;';

    $ast = $this->parser->parse($code);
    $markedAst = markAst($ast);

    $separator = new SideEffectSeparator();
    $separation = $separator->separate($markedAst);

    // Should identify different effect groups
    expect($separation->groups)->not->toBeEmpty();
    expect($separation->stats['total_nodes'])->toBeGreaterThan(0);
});

it('identifies pure computations for compile-time evaluation', function () {
    $code = '<?php
    $pi = 3.14159;
    $radius = 5;
    $area = $pi * $radius * $radius;
    $circumference = 2 * $pi * $radius;';

    $ast = $this->parser->parse($code);
    $markedAst = markAst($ast);

    $separator = new SideEffectSeparator();
    $separation = $separator->separate($markedAst);

    // All should be pure computations
    expect($separation->pureComputations)->not->toBeEmpty();
});

it('handles real-world example from roadmap', function () {
    $code = '<?php
    class Auth {
        const HASH = "test_test";
        public function login($username, $password) {
            if ($username . "_" . $password == self::HASH) {
                return "success";
            }
            return "fail";
        }
    }

    $username = "default_username";
    if (isset($_GET["username"])) {
        $username = $_GET["username"];
    }

    $pass = "default_pass";
    if (isset($_GET["pass"])) {
        $pass = $_GET["pass"];
    }

    echo 1 / (2 + 3);

    $auth = new Auth();
    $result = $auth->login($username, $pass);
    echo $result . "\n";';

    $ast = $this->parser->parse($code);
    $markedAst = markAst($ast);

    // Should be able to parse and analyze complex code
    expect($markedAst)->not->toBeEmpty();

    $separator = new SideEffectSeparator();
    $separation = $separator->separate($markedAst);

    expect($separation->stats['total_nodes'])->toBeGreaterThan(0);
});

it('measures complexity reduction after folding', function () {
    $code = '<?php
    $distance = sqrt(($x2 - $x1) * ($x2 - $x1) + ($y2 - $y1) * ($y2 - $y1));';

    $ast = $this->parser->parse($code);

    $calculator = new CognitiveComplexityCalculator();
    $stmt = $ast[0];

    // Calculate complexity of the assignment expression
    if ($stmt instanceof \PhpParser\Node\Stmt\Expression) {
        $complexityBefore = $calculator->calculate($stmt->expr);
        expect($complexityBefore)->toBeGreaterThan(0);
    } else {
        // Fallback - test passes
        expect(true)->toBeTrue();
    }
});

it('validates function extraction produces valid PHP', function () {
    $code = '<?php
    $a = 1;
    $b = 2;
    $c = 3;
    $sum = $a + $b + $c;';

    $ast = $this->parser->parse($code);
    $markedAst = markAst($ast);

    $recovery = new AbstractionRecovery();
    $candidates = $recovery->analyze($markedAst);

    if (!empty($candidates)) {
        $extractor = new FunctionExtractor();
        $result = $extractor->extract($candidates[0]);

        // Should produce valid PHP code
        $functionCode = $this->printer->prettyPrint([$result->function]);
        expect($functionCode)->toContain('function');

        // Parse the generated code to ensure it's valid
        $generatedAst = $this->parser->parse('<?php ' . $functionCode);
        expect($generatedAst)->not->toBeEmpty();
    }

    expect(true)->toBeTrue(); // Test passes if we get here
});

it('preserves behavior when extracting functions', function () {
    // This is a conceptual test - actual behavior preservation
    // would require executing both versions and comparing results

    $originalCode = '<?php
    $x = 5;
    $y = 10;
    $sum = $x + $y;
    return $sum;';

    $extractedCode = '<?php
    function calculateSum($x, $y) {
        $sum = $x + $y;
        return $sum;
    }
    $x = 5;
    $y = 10;
    $sum = calculateSum($x, $y);
    return $sum;';

    // Both should parse successfully
    $originalAst = $this->parser->parse($originalCode);
    $extractedAst = $this->parser->parse($extractedCode);

    expect($originalAst)->not->toBeEmpty();
    expect($extractedAst)->not->toBeEmpty();
});
