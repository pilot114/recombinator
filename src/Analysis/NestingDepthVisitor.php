<?php

declare(strict_types=1);

namespace Recombinator\Analysis;

use PhpParser\Node;

/**
 * Visitor для вычисления максимальной глубины вложенности
 */
class NestingDepthVisitor extends \PhpParser\NodeVisitorAbstract
{
    private int $currentDepth = 0;

    private int $maxDepth = 0;

    public function enterNode(Node $node): void
    {
        if ($this->isNestingNode($node)) {
            $this->currentDepth++;
            $this->maxDepth = max($this->maxDepth, $this->currentDepth);
        }
    }

    public function leaveNode(Node $node): void
    {
        if ($this->isNestingNode($node)) {
            $this->currentDepth--;
        }
    }

    public function getMaxDepth(): int
    {
        return $this->maxDepth;
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
               $node instanceof Node\Stmt\Catch_;
    }
}
