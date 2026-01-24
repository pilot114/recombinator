<?php

namespace Recombinator\Transformation;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * Visitor для упрощения условий
 */
class ConditionSimplifierVisitor extends NodeVisitorAbstract
{
    public function leaveNode(Node $node): ?Node
    {
        // Упрощаем вложенные if с одинаковыми условиями
        if ($node instanceof Node\Stmt\If_) {
            return $this->simplifyNestedIf($node);
        }

        return null;
    }

    /**
     * Упрощает вложенные if конструкции
     */
    private function simplifyNestedIf(Node\Stmt\If_ $if): ?Node\Stmt\If_
    {
        // Проверяем, есть ли один единственный вложенный if
        if (count($if->stmts) === 1) {
            $firstStmt = $if->stmts[0];

            // Если это тоже if без else
            if ($firstStmt instanceof Node\Stmt\If_ && !$firstStmt->else instanceof \PhpParser\Node\Stmt\Else_) {
                // Объединяем условия через &&
                $newCondition = new Node\Expr\BinaryOp\BooleanAnd(
                    $if->cond,
                    $firstStmt->cond
                );

                // Создаем новый if с объединенным условием
                $newIf = new Node\Stmt\If_(
                    $newCondition,
                    [
                        'stmts' => $firstStmt->stmts,
                        'elseifs' => $firstStmt->elseifs,
                        'else' => $firstStmt->else,
                    ]
                );

                return $newIf;
            }
        }

        return null;
    }
}
