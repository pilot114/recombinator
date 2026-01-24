<?php

declare(strict_types=1);

use PhpParser\Node;
use PhpParser\ParserFactory;
use Recombinator\Domain\FunctionCandidate;
use Recombinator\Domain\SideEffectType;

beforeEach(
    function (): void {
        $this->parser = new ParserFactory()->createForHostVersion();
    }
);

it(
    'matches pure block rule correctly',
    function (): void {
        $code = '<?php
    $a = 1;
    $b = 2;
    $c = 3;
    $d = 4;
    $result = $a + $b + $c + $d;';
        $ast = $this->parser->parse($code);

        $candidate = new FunctionCandidate(
            nodes: $ast,
            effectType: SideEffectType::PURE,
            size: 5,
            complexity: 10,
            usedVariables: [],
            definedVariables: ['$a', '$b', '$c', '$d', '$result'],
            returnVariable: '$result',
            startLine: 1,
            endLine: 6
        );

        expect($candidate->matchesPureBlockRule())->toBeTrue();
    }
);

it(
    'does not match pure block rule for small blocks',
    function (): void {
        $candidate = new FunctionCandidate(
            nodes: [],
            effectType: SideEffectType::PURE,
            size: 3, // Too small (< 5)
            complexity: 10,
            usedVariables: [],
            definedVariables: [],
        );

        expect($candidate->matchesPureBlockRule())->toBeFalse();
    }
);

it(
    'matches effect block rule correctly',
    function (): void {
        $candidate = new FunctionCandidate(
            nodes: [],
            effectType: SideEffectType::IO,
            size: 3,
            complexity: 5,
            usedVariables: [],
            definedVariables: [],
        );

        expect($candidate->matchesEffectBlockRule())->toBeTrue();
    }
);

it(
    'does not match effect block rule for PURE type',
    function (): void {
        $candidate = new FunctionCandidate(
            nodes: [],
            effectType: SideEffectType::PURE,
            size: 3,
            complexity: 5,
            usedVariables: [],
            definedVariables: [],
        );

        expect($candidate->matchesEffectBlockRule())->toBeFalse();
    }
);

it(
    'does not match effect block rule for MIXED type',
    function (): void {
        $candidate = new FunctionCandidate(
            nodes: [],
            effectType: SideEffectType::MIXED,
            size: 3,
            complexity: 5,
            usedVariables: [],
            definedVariables: [],
        );

        expect($candidate->matchesEffectBlockRule())->toBeFalse();
    }
);

it(
    'is viable when matches pure block rule',
    function (): void {
        $candidate = new FunctionCandidate(
            nodes: [],
            effectType: SideEffectType::PURE,
            size: 5,
            complexity: 10,
            usedVariables: [],
            definedVariables: [],
        );

        expect($candidate->isViable())->toBeTrue();
    }
);

it(
    'is viable when matches effect block rule',
    function (): void {
        $candidate = new FunctionCandidate(
            nodes: [],
            effectType: SideEffectType::IO,
            size: 3,
            complexity: 5,
            usedVariables: [],
            definedVariables: [],
        );

        expect($candidate->isViable())->toBeTrue();
    }
);

it(
    'calculates priority correctly for pure block',
    function (): void {
        $candidate = new FunctionCandidate(
            nodes: [],
            effectType: SideEffectType::PURE,
            size: 5,
            complexity: 10,
            usedVariables: ['$x', '$y'],
            definedVariables: [],
        );

        $priority = $candidate->getPriority();

        // Pure bonus (100) + size (50) + complexity (50) - params (6) = 194
        expect($priority)->toBeGreaterThan(100);
    }
);

it(
    'calculates lower priority for IO block',
    function (): void {
        $candidatePure = new FunctionCandidate(
            nodes: [],
            effectType: SideEffectType::PURE,
            size: 5,
            complexity: 10,
            usedVariables: [],
            definedVariables: [],
        );

        $candidateIO = new FunctionCandidate(
            nodes: [],
            effectType: SideEffectType::IO,
            size: 5,
            complexity: 10,
            usedVariables: [],
            definedVariables: [],
        );

        expect($candidatePure->getPriority())->toBeGreaterThan($candidateIO->getPriority());
    }
);

it(
    'suggests function name for pure block',
    function (): void {
        $candidate = new FunctionCandidate(
            nodes: [],
            effectType: SideEffectType::PURE,
            size: 5,
            complexity: 10,
            usedVariables: [],
            definedVariables: [],
            returnVariable: '$distance',
            startLine: 10,
            endLine: 15
        );

        $name = $candidate->suggestFunctionName();

        expect($name)->toContain('calculate');
    }
);

it(
    'suggests function name for IO block',
    function (): void {
        $candidate = new FunctionCandidate(
            nodes: [],
            effectType: SideEffectType::IO,
            size: 3,
            complexity: 5,
            usedVariables: [],
            definedVariables: [],
            startLine: 20,
            endLine: 23
        );

        $name = $candidate->suggestFunctionName();

        expect($name)->toContain('print');
    }
);

it(
    'gets function parameters correctly',
    function (): void {
        $candidate = new FunctionCandidate(
            nodes: [],
            effectType: SideEffectType::PURE,
            size: 5,
            complexity: 10,
            usedVariables: ['$x', '$y', '$z'],
            definedVariables: ['$z', '$result'],
        );

        $params = $candidate->getFunctionParameters();

        // Only $x and $y (not $z because it's defined)
        expect($params)->toContain('$x', '$y');
        expect($params)->not->toContain('$z');
    }
);

it(
    'handles empty parameters',
    function (): void {
        $candidate = new FunctionCandidate(
            nodes: [],
            effectType: SideEffectType::PURE,
            size: 5,
            complexity: 10,
            usedVariables: [],
            definedVariables: [],
        );

        $params = $candidate->getFunctionParameters();

        expect($params)->toBeEmpty();
    }
);
