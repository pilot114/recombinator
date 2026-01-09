<?php

declare(strict_types=1);

namespace Recombinator\Contract;

use PhpParser\Node;
use Recombinator\Domain\SideEffectType;

/**
 * Interface for side effect classifiers
 */
interface EffectClassifierInterface
{
    /**
     * Classify node for side effects
     */
    public function classify(Node $node): SideEffectType;

    /**
     * Check if function is pure
     */
    public function isPureFunction(string $functionName): bool;
}
