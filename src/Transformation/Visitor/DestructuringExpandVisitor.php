<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;

/**
 * Раскрывает деструктуризацию массива с константными значениями в отдельные присваивания:
 *
 *   [$low, $high] = [7, 8]  →  $low = 7; $high = 8;
 *
 * После этого VarToScalarVisitor подставляет скаляры в места использования,
 * а ConstFoldVisitor сворачивает интерполированные строки.
 */
#[VisitorMeta('Раскрытие деструктуризации с константами в отдельные присваивания')]
class DestructuringExpandVisitor extends BaseVisitor
{
    #[\Override]
    public function leaveNode(Node $node): int|Node|array|null
    {
        if (!$node instanceof Node\Stmt\Expression) {
            return parent::leaveNode($node);
        }

        $expr = $node->expr;
        if (!$expr instanceof Node\Expr\Assign) {
            return parent::leaveNode($node);
        }

        // LHS must be Array_ or List_ (list-style destructuring without explicit keys)
        if (!$expr->var instanceof Node\Expr\Array_ && !$expr->var instanceof Node\Expr\List_) {
            return parent::leaveNode($node);
        }

        // RHS must be Array_ with scalar/constant items
        if (!$expr->expr instanceof Node\Expr\Array_) {
            return parent::leaveNode($node);
        }

        $lhsItems = $expr->var->items;
        $rhsItems = $expr->expr->items;

        if (count($lhsItems) !== count($rhsItems)) {
            return parent::leaveNode($node);
        }

        $expansions = [];
        foreach ($lhsItems as $i => $lhsItem) {
            if ($lhsItem === null || $lhsItem->key !== null) {
                return parent::leaveNode($node);
            }

            if (!$lhsItem->value instanceof Node\Expr\Variable) {
                return parent::leaveNode($node);
            }

            $rhsItem = $rhsItems[$i] ?? null;
            if ($rhsItem === null || !$this->isScalarConstant($rhsItem->value)) {
                return parent::leaveNode($node);
            }

            $expansions[] = new Node\Stmt\Expression(
                new Node\Expr\Assign($lhsItem->value, $rhsItem->value)
            );
        }

        return $expansions !== [] ? $expansions : parent::leaveNode($node);
    }

    private function isScalarConstant(Node $node): bool
    {
        return $node instanceof Node\Scalar || $node instanceof Node\Expr\ConstFetch;
    }
}
