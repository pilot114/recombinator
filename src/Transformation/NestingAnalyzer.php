<?php

namespace Recombinator\Transformation;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * Visitor для анализа вложенности
 */
class NestingAnalyzer extends NodeVisitorAbstract
{
    private int $currentNesting = 0;

    private int $maxNesting = 0;

    private array $nestingLevels = [];

    private array $complexNodes = [];

    public function enterNode(Node $node): void
    {
        // Узлы, создающие вложенность
        if ($this->isNestingNode($node)) {
            $this->currentNesting++;
            $this->maxNesting = max($this->maxNesting, $this->currentNesting);
            $this->nestingLevels[] = $this->currentNesting;

            // Запоминаем сложные узлы (глубина > 3)
            if ($this->currentNesting > 3) {
                $this->complexNodes[] = [
                    'node' => $node,
                    'level' => $this->currentNesting,
                    'line' => $node->getStartLine() ?? 0,
                ];
            }
        }
    }

    public function leaveNode(Node $node): void
    {
        if ($this->isNestingNode($node)) {
            $this->currentNesting--;
        }
    }

    public function getMaxNesting(): int
    {
        return $this->maxNesting;
    }

    public function getAvgNesting(): float
    {
        if ($this->nestingLevels === []) {
            return 0.0;
        }

        return round(array_sum($this->nestingLevels) / count($this->nestingLevels), 2);
    }

    public function getComplexNodes(): array
    {
        return $this->complexNodes;
    }

    private function isNestingNode(Node $node): bool
    {
        return $node instanceof Node\Stmt\If_ ||
            $node instanceof Node\Stmt\ElseIf_ ||
            $node instanceof Node\Stmt\Else_ ||
            $node instanceof Node\Stmt\For_ ||
            $node instanceof Node\Stmt\Foreach_ ||
            $node instanceof Node\Stmt\While_ ||
            $node instanceof Node\Stmt\Do_ ||
            $node instanceof Node\Stmt\Switch_ ||
            $node instanceof Node\Stmt\Case_ ||
            $node instanceof Node\Stmt\TryCatch ||
            $node instanceof Node\Stmt\Catch_ ||
            $node instanceof Node\Expr\Closure ||
            $node instanceof Node\Expr\ArrowFunction;
    }
}
