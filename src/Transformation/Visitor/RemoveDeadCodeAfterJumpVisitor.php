<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;

/**
 * Removes unreachable statements after return / throw inside a block:
 *
 *   function() {
 *       throw new RuntimeException('x');
 *       return 'ok';           // ← removed
 *   }
 *
 * Applies to: function/method bodies, closures, if/else/while/for bodies.
 */
#[VisitorMeta('Удаление мёртвого кода после return/throw')]
class RemoveDeadCodeAfterJumpVisitor extends BaseVisitor
{
    #[\Override]
    public function leaveNode(Node $node): int|Node|array|null
    {
        $stmts = $this->extractStmts($node);
        if ($stmts === null) {
            return parent::leaveNode($node);
        }

        $cut = null;
        foreach ($stmts as $i => $stmt) {
            if ($cut !== null) {
                // Mark stmts after the jump for removal
                $stmts[$i]->setAttribute('remove', true);
            } elseif ($this->isTerminating($stmt)) {
                $cut = $i;
            }
        }

        if ($cut === null) {
            return parent::leaveNode($node);
        }

        // Return a modified clone with dead stmts stripped
        $clone        = clone $node;
        $clone->stmts = array_values(array_filter($stmts, fn(\PhpParser\Node\Stmt $s): bool => !$s->getAttribute('remove')));
        return $clone;
    }

    /** @return array<Node\Stmt>|null */
    private function extractStmts(Node $node): ?array
    {
        if (
            $node instanceof Node\Stmt\ClassMethod
            || $node instanceof Node\Stmt\Function_
            || $node instanceof Node\Expr\Closure
        ) {
            return $node->stmts ?? null;
        }

        return null;
    }

    private function isTerminating(Node\Stmt $stmt): bool
    {
        if ($stmt instanceof Node\Stmt\Return_) {
            return true;
        }

        if ($stmt instanceof Node\Stmt\Throw_) {
            return true;
        }

        // PHP 8: throw as expression statement
        return $stmt instanceof Node\Stmt\Expression && $stmt->expr instanceof Node\Expr\Throw_;
    }
}
