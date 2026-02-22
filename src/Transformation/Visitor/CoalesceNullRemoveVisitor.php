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
        if (
            $node instanceof Node\Expr\BinaryOp\Coalesce
            && $node->right instanceof Node\Expr\ConstFetch
            && strtolower($node->right->name->toString()) === 'null'
        ) {
            return $node->left;
        }

        return parent::leaveNode($node);
    }
}
