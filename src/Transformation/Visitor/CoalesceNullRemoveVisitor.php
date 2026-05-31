<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;

/**
 * Удаляет избыточный хвостовой операнд ?? null.
 *
 * X ?? null  →  X
 *
 * Паттерн всегда избыточен:
 *   - если X !== null  →  результат X  (одинаково с оператором и без)
 *   - если X === null  →  результат null  (одинаково с оператором и без)
 *
 * Работает снизу вверх, поэтому цепочки сворачиваются за один проход:
 *   $a ?? ($b ?? null)  →  $a ?? $b
 */
#[VisitorMeta('Удаление избыточного ?? null (X ?? null → X)')]
class CoalesceNullRemoveVisitor extends BaseVisitor
{
    #[\Override]
    public function leaveNode(Node $node): int|Node|array|null
    {
        if ($node instanceof Node\Expr\BinaryOp\Coalesce) {
            // X ?? null → X
            if (
                $node->right instanceof Node\Expr\ConstFetch
                && strtolower($node->right->name->toString()) === 'null'
            ) {
                return $node->left;
            }

            // null ?? X → X  (null is always null, so right side is always taken)
            if (
                $node->left instanceof Node\Expr\ConstFetch
                && strtolower($node->left->name->toString()) === 'null'
            ) {
                return $node->right;
            }

            // scalar ?? anything → scalar  (scalars are never null)
            if ($node->left instanceof Node\Scalar) {
                return $node->left;
            }

            // true/false ?? anything → true/false  (never null)
            if (
                $node->left instanceof Node\Expr\ConstFetch
                && in_array(strtolower($node->left->name->toString()), ['true', 'false'], true)
            ) {
                return $node->left;
            }
        }

        return parent::leaveNode($node);
    }
}
