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
    public function enterNode(Node $node)
    {
        if ($node instanceof Node\Stmt\ClassMethod) {
            if (
                $node->stmts[0] instanceof Node\Stmt\If_ &&
                $node->stmts[1] instanceof Node\Stmt\Return_
            ) {
                $if = $node->stmts[0];
                $second = $node->stmts[1]->expr;
                if ($if->stmts[0] instanceof Node\Stmt\Return_) {
                    $first = $if->stmts[0]->expr;
                    $ternar = new Node\Expr\Ternary($node->stmts[0]->cond, $first, $second);
                    $nodeNew = clone $node;
                    $nodeNew->stmts = [ new Node\Stmt\Return_($ternar) ];
                    $node->setAttribute('replace', $nodeNew);
                }
            }
        }
    }
}