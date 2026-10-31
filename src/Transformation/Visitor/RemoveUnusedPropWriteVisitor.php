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
 * Условия:
 * - запись на верхнем уровне (в методе `$new->path = $path` — запись в объект,
 *   который вернут и прочитают как `$this->path`);
 * - свойство с этим именем нигде не читается ни у какого объекта;
 * - переменная объекта используется только как `$var->prop`: переданный дальше
 *   объект могут прочитать целиком (var_dump, json_encode, foreach);
 * - правая часть без побочных эффектов.
 */
#[VisitorMeta('Удаление мёртвых записей в свойства объектов', targets: [Node\Expr\PropertyFetch::class])]
class RemoveUnusedPropWriteVisitor extends BaseVisitor
{
    /** @var array<string, true> имена свойств, которые где-то читаются ('*' — динамическое чтение) */
    private array $readProps = [];

    /** @var array<string, true> переменные верхнего уровня, которые используются не только как $var->prop */
    private array $escaping = [];

    #[\Override]
    public function beforeTraverse(array $nodes): ?array
    {
        parent::beforeTraverse($nodes);
        $this->readProps = [];
        $this->escaping  = [];

        $writeTargets = [];
        foreach ($this->findNode(Node\Expr\Assign::class) as $assign) {
            if ($assign->var instanceof Node\Expr\PropertyFetch) {
                $writeTargets[spl_object_id($assign->var)] = true;
            }
        }

        foreach ($this->findNode(Node\Expr\PropertyFetch::class) as $fetch) {
            if (!isset($writeTargets[spl_object_id($fetch)])) {
                $this->readProps[$fetch->name instanceof Node\Identifier ? $fetch->name->toString() : '*'] = true;
            }
        }

        foreach ($this->findNode(Node\Expr\NullsafePropertyFetch::class) as $fetch) {
            $this->readProps[$fetch->name instanceof Node\Identifier ? $fetch->name->toString() : '*'] = true;
        }

        \Recombinator\Transformation\BodyInliner::walkOwnScope($nodes, function (Node $node): void {
            if (!$node instanceof Node\Expr\Variable || !is_string($node->name)) {
                return;
            }

            $use = $node->getAttribute('parent');
            $ok  = $use instanceof Node\Expr\PropertyFetch && $use->var === $node
                || $use instanceof Node\Expr\Assign && $use->var === $node;
            if (!$ok) {
                $this->escaping[$node->name] = true;
            }
        });

        return null;
    }

    #[\Override]
    public function enterNode(Node $node): ?int
    {
        // Только верхний уровень: глубже не спускаемся
        if ($node instanceof Node\FunctionLike || $node instanceof Node\Stmt\ClassLike) {
            return \PhpParser\NodeVisitor::DONT_TRAVERSE_CHILDREN;
        }

        if (!$node instanceof Node\Stmt\Expression || !$node->expr instanceof Node\Expr\Assign) {
            return null;
        }

        $lhs = $node->expr->var;
        if (
            $lhs instanceof Node\Expr\PropertyFetch
            && $lhs->var instanceof Node\Expr\Variable
            && is_string($lhs->var->name)
            && $lhs->name instanceof Node\Identifier
            && !isset($this->readProps[$lhs->name->toString()])
            && !isset($this->readProps['*'])
            && !isset($this->escaping[$lhs->var->name])
            && $this->isPure($node->expr->expr)
        ) {
            $node->setAttribute('remove', true);
        }

        return null;
    }

    private function isPure(Node $node): bool
    {
        if ($node instanceof Node\Scalar || $node instanceof Node\Expr\ConstFetch || $node instanceof Node\Expr\Variable) {
            return true;
        }

        if ($node instanceof Node\Expr\Array_) {
            return array_all(
                $node->items,
                fn(Node\ArrayItem $item): bool => $this->isPure($item->value) && (!$item->key instanceof Node\Expr || $this->isPure($item->key)),
            );
        }

        if ($node instanceof Node\Expr\BinaryOp) {
            return $this->isPure($node->left) && $this->isPure($node->right);
        }

        return false;
    }
}
