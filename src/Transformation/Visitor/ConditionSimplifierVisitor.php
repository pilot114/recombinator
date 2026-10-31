<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;

/**
 * Склеивает вложенные if без веток else: `if (a) { if (b) { … } }` → `if (a && b) { … }`.
 * `&&` вычисляет `b` только при истинном `a` — как и вложенный if.
 */
#[VisitorMeta('Склейка вложенных if без else в одно условие', targets: [Node\Stmt\If_::class])]
class ConditionSimplifierVisitor extends BaseVisitor
{
    #[\Override]
    public function leaveNode(Node $node): ?Node
    {
        if (!$node instanceof Node\Stmt\If_ || !$this->withoutBranches($node) || count($node->stmts) !== 1) {
            return null;
        }

        $inner = $node->stmts[0];
        if (!$inner instanceof Node\Stmt\If_ || !$this->withoutBranches($inner)) {
            return null;
        }

        return new Node\Stmt\If_(
            self::conjunction($node->cond, $inner->cond),
            ['stmts' => $inner->stmts],
            $node->getAttributes(),
        );
    }

    /** `a && (b && c)` → `a && b && c`: && ассоциативен, порядок вычисления тот же. */
    private static function conjunction(Node\Expr $left, Node\Expr $right): Node\Expr\BinaryOp\BooleanAnd
    {
        return $right instanceof Node\Expr\BinaryOp\BooleanAnd
            ? new Node\Expr\BinaryOp\BooleanAnd(self::conjunction($left, $right->left), $right->right)
            : new Node\Expr\BinaryOp\BooleanAnd($left, $right);
    }

    /** Ветки elseif/else зависят от условия своего if: при склейке они изменили бы смысл. */
    private function withoutBranches(Node\Stmt\If_ $if): bool
    {
        return $if->elseifs === [] && !$if->else instanceof Node\Stmt\Else_;
    }
}
