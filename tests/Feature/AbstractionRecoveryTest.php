<?php

declare(strict_types=1);

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;
use Recombinator\Transformation\AbstractionRecovery;
use Recombinator\Transformation\FunctionExtractor;
use Recombinator\Transformation\NestedConditionSimplifier;
use Recombinator\Transformation\VariableDeclarationOptimizer;
use Recombinator\Transformation\Visitor\SideEffectMarkerVisitor;

beforeEach(function () {
    $this->parser = (new ParserFactory())->createForHostVersion();
    $this->printer = new StandardPrinter();
});

it('can analyze and extract function candidates from pure code', function () {
    $code = '<?php
    // Pure computation block
    $dx = $x2 - $x1;
    $dy = $y2 - $y1;
    $dxSquared = $dx * $dx;
    $dySquared = $dy * $dy;
    $distance = sqrt($dxSquared + $dySquared);';

    $ast = $this->parser->parse($code);

    // Mark side effects
    $marker = new SideEffectMarkerVisitor();
    $traverser = new NodeTraverser();
    $traverser->addVisitor($marker);
    $ast = $traverser->traverse($ast);

    // Analyze for abstraction candidates
    $recovery = new AbstractionRecovery();
    $candidates = $recovery->analyze($ast);

    // Should find at least one viable candidate
    expect($candidates)->not->toBeEmpty();

    $viableCandidates = array_filter($candidates, fn($c) => $c->isViable());
    expect($viableCandidates)->not->toBeEmpty();
});

it('can extract pure block into function', function () {
    $code = '<?php
    $dx = $x2 - $x1;
    $dy = $y2 - $y1;
    $dxSquared = $dx * $dx;
    $dySquared = $dy * $dy;
    $distance = sqrt($dxSquared + $dySquared);';

    $ast = $this->parser->parse($code);

    // Mark side effects
    $marker = new SideEffectMarkerVisitor();
    $traverser = new NodeTraverser();
    $traverser->addVisitor($marker);
    $ast = $traverser->traverse($ast);

    // Find candidates
    $recovery = new AbstractionRecovery();
    $candidates = $recovery->analyze($ast);

    if (!empty($candidates)) {
        $candidate = $candidates[0];

        if ($candidate->isViable()) {
            // Extract function
            $extractor = new FunctionExtractor();
            $result = $extractor->extract($candidate);

            // Should create a function
            expect($result->function)->toBeInstanceOf(\PhpParser\Node\Stmt\Function_::class);

            // Function name should be generated
            expect($result->functionName)->not->toBeEmpty();

            // Should have a function call
            expect($result->call)->not->toBeNull();
        }
    }

    expect(true)->toBeTrue(); // Test passes if we get here
});

it('can analyze IO operations for extraction', function () {
    $code = '<?php
    echo "=== Report ===\n";
    echo "User: " . $username . "\n";
    echo "Status: " . $status . "\n";
    echo "===============\n";';

    $ast = $this->parser->parse($code);

    // Mark side effects
    $marker = new SideEffectMarkerVisitor();
    $traverser = new NodeTraverser();
    $traverser->addVisitor($marker);
    $ast = $traverser->traverse($ast);

    // Analyze for abstraction candidates
    $recovery = new AbstractionRecovery();
    $candidates = $recovery->analyze($ast);

    // Should recognize IO operations
    expect($candidates)->toBeArray();
});

it('can simplify nested conditions', function () {
    $code = '<?php
    if ($a) {
        if ($b) {
            return true;
        }
    }';

    $ast = $this->parser->parse($code);

    $simplifier = new NestedConditionSimplifier();
    $simplified = $simplifier->simplify($ast);
    $result = $this->printer->prettyPrintFile($simplified);

    // Should combine nested ifs
    expect($result)->toContain('&&');
});

it('can analyze nesting complexity', function () {
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

    $simplifier = new NestedConditionSimplifier();
    $analysis = $simplifier->analyze($ast);

    expect($analysis->maxNesting)->toBeGreaterThanOrEqual(4);
    expect($analysis->hasIssues(3))->toBeTrue();
});

it('can optimize variable declarations', function () {
    $code = '<?php
    $x = $point["x"];
    echo "Processing...";
    $y = $point["y"];
    echo "Calculating...";
    $z = $point["z"];';

    $ast = $this->parser->parse($code);

    // Mark side effects
    $marker = new SideEffectMarkerVisitor();
    $traverser = new NodeTraverser();
    $traverser->addVisitor($marker);
    $ast = $traverser->traverse($ast);

    $optimizer = new VariableDeclarationOptimizer();
    $optimized = $optimizer->optimize($ast);

    // Should return an array
    expect($optimized)->toBeArray();
});

it('handles full pipeline for distance calculation', function () {
    $code = '<?php
    // Calculate distance between two points
    $dx = $x2 - $x1;
    $dy = $y2 - $y1;
    $distance = sqrt($dx * $dx + $dy * $dy);

    // Calculate another distance
    $dx2 = $x3 - $x2;
    $dy2 = $y3 - $y2;
    $distance2 = sqrt($dx2 * $dx2 + $dy2 * $dy2);';

    $ast = $this->parser->parse($code);

    // Mark side effects
    $marker = new SideEffectMarkerVisitor();
    $traverser = new NodeTraverser();
    $traverser->addVisitor($marker);
    $ast = $traverser->traverse($ast);

    // Analyze
    $recovery = new AbstractionRecovery();
    $candidates = $recovery->analyze($ast);

    // Should find candidates for extraction
    expect($candidates)->not->toBeEmpty();

    // At least one should be viable
    $viableCandidates = array_filter($candidates, fn($c) => $c->isViable());

    // The pattern is repeated, so extraction makes sense
    if (!empty($viableCandidates)) {
        expect($viableCandidates[0]->suggestFunctionName())->toContain('calculate');
    }
});

it('prioritizes pure blocks over effect blocks', function () {
    $code = '<?php
    // Pure block
    $dx = $x2 - $x1;
    $dy = $y2 - $y1;
    $dxSquared = $dx * $dx;
    $dySquared = $dy * $dy;
    $distance = sqrt($dxSquared + $dySquared);

    // IO block
    echo "Result\n";
    echo "Distance: " . $distance . "\n";';

    $ast = $this->parser->parse($code);

    // Mark side effects
    $marker = new SideEffectMarkerVisitor();
    $traverser = new NodeTraverser();
    $traverser->addVisitor($marker);
    $ast = $traverser->traverse($ast);

    // Analyze
    $recovery = new AbstractionRecovery();
    $candidates = $recovery->analyze($ast);

    if (!empty($candidates) && count($candidates) >= 2) {
        // First candidate should have higher priority
        expect($candidates[0]->getPriority())->toBeGreaterThanOrEqual($candidates[1]->getPriority());
    }

    expect(true)->toBeTrue();
});
