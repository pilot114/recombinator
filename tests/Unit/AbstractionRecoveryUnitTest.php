<?php

declare(strict_types=1);

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use Recombinator\Domain\SideEffectType;
use Recombinator\Transformation\AbstractionRecovery;
use Recombinator\Transformation\Visitor\SideEffectMarkerVisitor;

beforeEach(
    function (): void {
        $this->parser = new ParserFactory()->createForHostVersion();
        $this->recovery = new AbstractionRecovery();
    }
);

function markEffects(array $ast): array
{
    $traverser = new NodeTraverser();
    $traverser->addVisitor(new SideEffectMarkerVisitor());
    return $traverser->traverse($ast);
}

it(
    'identifies pure block candidates with min 5 statements',
    function (): void {
        $code = '<?php
$a = 1;
$b = 2;
$c = $a + $b;
$d = $c * 2;
$e = $d - 1;';

        $ast = $this->parser->parse($code);
        $ast = markEffects($ast);

        $candidates = $this->recovery->analyze($ast);

        $pureCandidates = array_filter(
            $candidates,
            fn($c): bool => $c->effectType === SideEffectType::PURE
        );

        expect($pureCandidates)->not->toBeEmpty();
    }
);

it(
    'identifies IO effect block candidates',
    function (): void {
        // IO block with 3+ statements (minEffectBlockSize = 3)
        $code = '<?php
echo "line1\n";
echo "line2\n";
echo "line3\n";';

        $ast = $this->parser->parse($code);
        $ast = markEffects($ast);

        $candidates = $this->recovery->analyze($ast);

        $ioCandidates = array_filter(
            $candidates,
            fn($c): bool => $c->effectType === SideEffectType::IO
        );

        expect($ioCandidates)->not->toBeEmpty();
    }
);

it(
    'returns empty for single-statement code',
    function (): void {
        $code = '<?php $x = 1;';
        $ast = $this->parser->parse($code);
        $ast = markEffects($ast);

        $candidates = $this->recovery->analyze($ast);

        expect($candidates)->toBeEmpty();
    }
);

it(
    'assigns correct priorities to candidates',
    function (): void {
        $code = '<?php
$a = 1;
$b = 2;
$c = $a + $b;
$d = $c * 2;
$e = $d - 1;';

        $ast = $this->parser->parse($code);
        $ast = markEffects($ast);

        $candidates = $this->recovery->analyze($ast);

        if (count($candidates) >= 2) {
            // Sorted by priority descending
            expect($candidates[0]->getPriority())->toBeGreaterThanOrEqual($candidates[1]->getPriority());
        }

        expect($candidates)->toBeArray();
    }
);

it(
    'handles code with no folding opportunities',
    function (): void {
        $code = '<?php $x = 1; $y = 2;';
        $ast = $this->parser->parse($code);
        $ast = markEffects($ast);

        $candidates = $this->recovery->analyze($ast);

        expect($candidates)->toBeEmpty();
    }
);
