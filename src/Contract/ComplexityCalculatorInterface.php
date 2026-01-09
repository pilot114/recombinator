<?php

declare(strict_types=1);

namespace Recombinator\Contract;

use PhpParser\Node;

/**
 * Interface for complexity calculators
 */
interface ComplexityCalculatorInterface
{
    /**
     * Calculate complexity for given nodes
     *
     * @param array<Node> $nodes
     */
    public function calculate(array $nodes): int;

    /**
     * Get complexity level (low, moderate, high, very high) for given value
     */
    public function getComplexityLevel(int $value): string;
}
