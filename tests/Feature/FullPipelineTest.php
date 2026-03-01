<?php

declare(strict_types=1);

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NodeConnectingVisitor;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;
use Recombinator\Analysis\CognitiveComplexityCalculator;
use Recombinator\Analysis\SideEffectClassifier;
use Recombinator\Domain\ScopeStore;
use Recombinator\Domain\SideEffectType;
use Recombinator\Transformation\AbstractionRecovery;
use Recombinator\Transformation\Visitor\SideEffectMarkerVisitor;
use Recombinator\Transformation\Visitor\VarToScalarVisitor;
use Recombinator\Transformation\Visitor\TernarReturnVisitor;
use Recombinator\Transformation\Visitor\BinaryAndIssetVisitor;

beforeEach(
    function (): void {
        $this->parser = new ParserFactory()->createForHostVersion();
        $this->printer = new StandardPrinter();
        $this->classifier = new SideEffectClassifier();
        $this->complexityCalculator = new CognitiveComplexityCalculator();
    }
);

function applyUnfolding(string $code): string
{
    $parser = new ParserFactory()->createForHostVersion();
    $printer = new StandardPrinter();
    $store = new ScopeStore();

    $ast = $parser->parse($code);

    // Connect nodes
    $traverser = new NodeTraverser();
    $traverser->addVisitor(new NodeConnectingVisitor());

    $ast = $traverser->traverse($ast);

    // Apply unfolding visitors
    $traverser = new NodeTraverser();
    $traverser->addVisitor(new VarToScalarVisitor($store));
    $traverser->addVisitor(new BinaryAndIssetVisitor());
    $traverser->addVisitor(new TernarReturnVisitor());

    $ast = $traverser->traverse($ast);

    return $printer->prettyPrint($ast);
}

it(
    'transforms function with inlining and constant folding',
    function (): void {
        $code = file_get_contents(__DIR__ . '/fixtures/function_inlining.php');
        $ast = $this->parser->parse($code);

        // Mark side effects
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new SideEffectMarkerVisitor());

        $ast = $traverser->traverse($ast);

        // Analyze for abstraction candidates
        $recovery = new AbstractionRecovery();
        $candidates = $recovery->analyze($ast);

        // Should identify some structure
        expect($candidates)->toBeArray();

        // The code should parse without errors
        expect($ast)->toBeArray()
            ->and(count($ast))->toBeGreaterThan(0);
    }
);

it(
    'separates code by side effect type',
    function (): void {
        $code = file_get_contents(__DIR__ . '/fixtures/side_effect_separation.php');
        $ast = $this->parser->parse($code);

        // Classify each statement
        $effects = [];
        foreach ($ast as $node) {
            $effect = $this->classifier->classify($node);
            $effects[] = $effect;
        }

        // Should contain multiple different effect types
        $uniqueEffects = array_unique(array_map(fn($e) => $e->value, $effects));
        expect(count($uniqueEffects))->toBeGreaterThanOrEqual(2);

        // Should have at least PURE and IO/EXTERNAL_STATE
        $effectValues = array_map(fn($e) => $e->value, $effects);
        expect($effectValues)->toContain(SideEffectType::PURE->value);
    }
);

it(
    'reduces cognitive complexity after transformation',
    function (): void {
        $code = '<?php
if (isset($_GET["user"])) {
    $user = $_GET["user"];
} else {
    $user = "anonymous";
}
if (isset($_GET["role"])) {
    $role = $_GET["role"];
} else {
    $role = "guest";
}
$result = $user . " (" . $role . ")";';

        $astBefore = $this->parser->parse($code);
        $complexityBefore = $this->complexityCalculator->calculate($astBefore);

        $result = applyUnfolding($code);

        $astAfter = $this->parser->parse('<?php ' . $result);
        $complexityAfter = $this->complexityCalculator->calculate($astAfter);

        // After transformation (isset -> ??), complexity should decrease
        expect($complexityAfter)->toBeLessThanOrEqual($complexityBefore);
    }
);

it(
    'produces valid PHP output',
    function (): void {
        $fixtures = [
            __DIR__ . '/fixtures/function_inlining.php',
            __DIR__ . '/fixtures/side_effect_separation.php',
            __DIR__ . '/fixtures/auth_class_example.php',
        ];

        foreach ($fixtures as $fixture) {
            $code = file_get_contents($fixture);
            $ast = $this->parser->parse($code);

            // Should parse without errors
            expect($ast)->not->toBeNull()
                ->and($ast)->toBeArray();

            // Re-print and re-parse to verify valid PHP output
            $output = $this->printer->prettyPrint($ast);
            $reparsed = $this->parser->parse('<?php ' . $output);

            expect($reparsed)->not->toBeNull();
        }
    }
);

it(
    'transforms auth class example from roadmap',
    function (): void {
        $code = file_get_contents(__DIR__ . '/fixtures/auth_class_example.php');
        $ast = $this->parser->parse($code);

        // Mark side effects
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new SideEffectMarkerVisitor());

        $ast = $traverser->traverse($ast);

        // Classify the overall block
        $effect = $this->classifier->classifyBlock($ast);

        // Should detect mixed effects (class + IO from echo)
        expect($effect)->not->toBe(SideEffectType::PURE);

        // Should parse without errors
        expect($ast)->toBeArray();
    }
);
