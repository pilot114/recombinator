<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;

/**
 * Удаляет избыточный хвостовой операнд ?? null.
 *
 * X ?? null  →  X
 *
 * Паттерн избыточен по значению:
 *   - если X !== null  →  результат X  (одинаково с оператором и без)
 *   - если X === null  →  результат null  (одинаково с оператором и без)
 *
 * Но ?? ещё и подавляет предупреждение о несуществующих переменной, ключе массива,
 * свойстве: `$params['driverClass'] ?? null` без ?? даёт «Undefined array key».
 * Поэтому X — не обращение к переменной, элементу или свойству.
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
                && !$this->mayBeUndefined($node->left)
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

    private function mayBeUndefined(Node\Expr $expr): bool
    {
        if ($expr instanceof Node\Expr\BinaryOp\Coalesce) {
            return $this->mayBeUndefined($expr->right);
        }

        return $expr instanceof Node\Expr\Variable
            || $expr instanceof Node\Expr\ArrayDimFetch
            || $expr instanceof Node\Expr\PropertyFetch
            || $expr instanceof Node\Expr\NullsafePropertyFetch
            || $expr instanceof Node\Expr\StaticPropertyFetch;
    }
}
