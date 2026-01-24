<?php

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;
use Recombinator\Domain\ScopeStore;

/**
 * Подмена:
 *
 * if(x) { return a; } else { return b };
 * или
 * if(x) { return a; } return b;
 * на
 * return x ? a : b;
 */
class TernarReturnVisitor extends BaseVisitor
{
    public function enterNode(Node $node): int|Node|array|null
    {
        if (!$node instanceof Node\Stmt\ClassMethod) {
            return null;
        }

        $stmts = $node->stmts;
        if ($stmts === null || !isset($stmts[0], $stmts[1])) {
            return null;
        }

        if (!$stmts[0] instanceof Node\Stmt\If_ || !$stmts[1] instanceof Node\Stmt\Return_) {
            return null;
        }

        $if = $stmts[0];
        $second = $stmts[1]->expr;
        if (!$second instanceof \PhpParser\Node\Expr) {
            return null;
        }

        $ifStmts = $if->stmts;
        if (!isset($ifStmts[0]) || !$ifStmts[0] instanceof Node\Stmt\Return_) {
            return null;
        }

        $first = $ifStmts[0]->expr;
        if (!$first instanceof \PhpParser\Node\Expr) {
            return null;
        }

        $ternar = new Node\Expr\Ternary($stmts[0]->cond, $first, $second);
        $nodeNew = clone $node;
        $nodeNew->stmts = [new Node\Stmt\Return_($ternar)];

        $node->setAttribute('replace', $nodeNew);

        return null;
    }
}
