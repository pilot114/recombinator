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

        // function &f(): вернуть по ссылке можно только переменную, не тернарный оператор
        if ($stmts === null || $stmts === [] || ($node instanceof Node\FunctionLike && $node->returnsByRef())) {
            return null;
        }

        // Цепочка `if (a) return x; if (b) return y; return z;` сворачивается целиком
        // за один обход: иначе каждое звено ждало бы следующего прохода конвейера
        $changed = false;
        while (($folded = $this->foldTail($stmts)) !== null) {
            $stmts   = $folded;
            $changed = true;
        }

        if ($changed) {
            $nodeNew        = clone $node;
            $nodeNew->stmts = $stmts;
            $node->setAttribute('replace', $nodeNew);
        }

        return null;
    }

    /**
     * Сворачивает хвост списка операторов по одному из паттернов.
     *
     * @param  array<Node\Stmt> $stmts
     * @return array<Node\Stmt>|null null — паттерн не найден
     */
    private function foldTail(array $stmts): ?array
    {
        $count = count($stmts);
        if ($count === 0) {
            return null;
        }

        $last = $stmts[$count - 1];

        // Паттерн 1: if(cond) { return a; } else { return b; }
        if ($last instanceof Node\Stmt\If_ && $last->else instanceof Node\Stmt\Else_ && $last->elseifs === []) {
            $ifReturn   = $this->getSingleReturn($last->stmts);
            $elseReturn = $this->getSingleReturn($last->else->stmts);

            if ($ifReturn instanceof Node\Expr && $elseReturn instanceof Node\Expr) {
                return [
                    ...array_slice($stmts, 0, $count - 1),
                    new Node\Stmt\Return_(new Node\Expr\Ternary($last->cond, $ifReturn, $elseReturn)),
                ];
            }
        }

        // Паттерн 2: if(cond) { return a; } return b;
        if ($count >= 2) {
            $prev = $stmts[$count - 2];
            if (
                $prev instanceof Node\Stmt\If_
                && !$prev->else instanceof Node\Stmt\Else_
                && $prev->elseifs === []
                && $last instanceof Node\Stmt\Return_
            ) {
                $ifReturn = $this->getSingleReturn($prev->stmts);
                $elseExpr = $last->expr;

                if ($ifReturn instanceof Node\Expr && $elseExpr instanceof Node\Expr) {
                    return [
                        ...array_slice($stmts, 0, $count - 2),
                        new Node\Stmt\Return_(new Node\Expr\Ternary($prev->cond, $ifReturn, $elseExpr)),
                    ];
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
