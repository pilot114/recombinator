<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;

/**
 * Подмена:
 *
 * if(x) { return a; } else { return b; }
 * или
 * if(x) { return a; } return b;
 * на
 * return x ? a : b;
 *
 * Работает для ClassMethod и Function_.
 * Приставочные стейтменты перед паттерном сохраняются.
 */
#[VisitorMeta('if(x){return a;} else {return b;} → return x ? a : b')]
class TernarReturnVisitor extends BaseVisitor
{
    public function enterNode(Node $node): int|Node|array|null
    {
        $stmts = match (true) {
            $node instanceof Node\Stmt\ClassMethod,
            $node instanceof Node\Stmt\Function_ => $node->stmts,
            default => null,
        };

        if ($stmts === null || $stmts === []) {
            return null;
        }

        $count = count($stmts);
        $last  = $stmts[$count - 1];

        // Паттерн 1: if(cond) { return a; } else { return b; }
        if ($last instanceof Node\Stmt\If_ && $last->else !== null && $last->elseifs === []) {
            $ifReturn   = $this->getSingleReturn($last->stmts);
            $elseReturn = $this->getSingleReturn($last->else->stmts);

            if ($ifReturn !== null && $elseReturn !== null) {
                $nodeNew        = clone $node;
                $nodeNew->stmts = [
                    ...array_slice($stmts, 0, $count - 1),
                    new Node\Stmt\Return_(new Node\Expr\Ternary($last->cond, $ifReturn, $elseReturn)),
                ];
                $node->setAttribute('replace', $nodeNew);
                return null;
            }
        }

        // Паттерн 2: if(cond) { return a; } return b;
        if ($count >= 2) {
            $prev = $stmts[$count - 2];
            if (
                $prev instanceof Node\Stmt\If_
                && $prev->else === null
                && $prev->elseifs === []
                && $last instanceof Node\Stmt\Return_
            ) {
                $ifReturn = $this->getSingleReturn($prev->stmts);
                $elseExpr = $last->expr;

                if ($ifReturn !== null && $elseExpr instanceof Node\Expr) {
                    $nodeNew        = clone $node;
                    $nodeNew->stmts = [
                        ...array_slice($stmts, 0, $count - 2),
                        new Node\Stmt\Return_(new Node\Expr\Ternary($prev->cond, $ifReturn, $elseExpr)),
                    ];
                    $node->setAttribute('replace', $nodeNew);
                    return null;
                }
            }
        }

        return null;
    }

    /** @param array<Node\Stmt> $stmts */
    private function getSingleReturn(array $stmts): ?Node\Expr
    {
        if (count($stmts) !== 1 || !$stmts[0] instanceof Node\Stmt\Return_) {
            return null;
        }

        $expr = $stmts[0]->expr;
        return $expr instanceof Node\Expr ? $expr : null;
    }
}
