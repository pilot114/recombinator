<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;

/**
 * Удаляет присваивания свойств объекта ($obj->prop = expr), если свойство
 * больше нигде не читается в коде. Это позволяет убрать "мёртвые" записи
 * вроде $whiskers->stepCounter = new Counter(...) после того, как все
 * чтения $whiskers->stepCounter уже были развёрнуты в скаляры.
 *
 * Работает только на верхнем уровне (не внутри методов класса).
 */
#[VisitorMeta('Удаление мёртвых записей в свойства объектов')]
class RemoveUnusedPropWriteVisitor extends BaseVisitor
{
    /**
     * prop reads: "$varName->$propName" seen outside of Assign LHS
     * key: "varName::propName"
     *
     * @var array<string, true>
     */
    private array $readProps = [];

    #[\Override]
    public function beforeTraverse(array $nodes): ?array
    {
        parent::beforeTraverse($nodes);
        $this->readProps = [];

        $assignPropLhsIds = [];

        // Collect all PropertyFetch nodes on the LHS of Assign
        foreach ($this->findNode(Node\Expr\Assign::class) as $assign) {
            if ($assign->var instanceof Node\Expr\PropertyFetch) {
                $assignPropLhsIds[spl_object_id($assign->var)] = true;
            }
        }

        // All PropertyFetch NOT on assign LHS = reads
        foreach ($this->findNode(Node\Expr\PropertyFetch::class) as $fetch) {
            if (isset($assignPropLhsIds[spl_object_id($fetch)])) {
                continue;
            }

            if (
                $fetch->var instanceof Node\Expr\Variable
                && is_string($fetch->var->name)
                && $fetch->name instanceof Node\Identifier
            ) {
                $key = $fetch->var->name . '::' . $fetch->name->toString();
                $this->readProps[$key] = true;
            }
        }

        return null;
    }

    #[\Override]
    public function enterNode(Node $node): int|Node|array|null
    {
        if (!$node instanceof Node\Stmt\Expression) {
            return null;
        }

        $expr = $node->expr;
        if (!$expr instanceof Node\Expr\Assign) {
            return null;
        }

        $lhs = $expr->var;
        if (
            $lhs instanceof Node\Expr\PropertyFetch
            && $lhs->var instanceof Node\Expr\Variable
            && is_string($lhs->var->name)
            && $lhs->name instanceof Node\Identifier
        ) {
            $key = $lhs->var->name . '::' . $lhs->name->toString();
            // Dead write — but only if RHS is side-effect free
            if (!isset($this->readProps[$key]) && $this->isPure($expr->expr)) {
                $node->setAttribute('remove', true);
            }
        }

        return null;
    }

    private function isPure(Node $node): bool
    {
        if ($node instanceof Node\Scalar) {
            return true;
        }

        if ($node instanceof Node\Expr\ConstFetch) {
            return true;
        }

        if ($node instanceof Node\Expr\Variable) {
            return true;
        }

        if ($node instanceof Node\Expr\New_) {
            // new ClassName(constArgs) is side-effect free if we're removing it
            return true;
        }

        if ($node instanceof Node\Expr\Array_) {
            return array_all($node->items, fn($item): bool => !($item && !$this->isPure($item->value)));
        }

        if ($node instanceof Node\Expr\BinaryOp) {
            return $this->isPure($node->left) && $this->isPure($node->right);
        }

        return false;
    }
}
