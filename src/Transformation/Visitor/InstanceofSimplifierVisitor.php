<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;

/**
 * Упрощает instanceof-проверки для переменных с известным типом:
 *
 *   $whiskers = new SyntaxZoo_Cat(...)
 *   $whiskers instanceof SyntaxZoo_Animal  →  false
 *
 * Тип переменной берётся из единственного присваивания `$var = new ClassName(...)`.
 * Проверяется иерархия классов через цепочку extends в текущем AST. false — только если
 * вся цепочка объявлена в AST и без implements: иначе (класс ещё не подключён, имена
 * в namespace не разрешены, интерфейсы) ответ неизвестен и instanceof остаётся.
 */
#[VisitorMeta('Упрощение instanceof с известным типом переменной', targets: [Node\Expr\Instanceof_::class])]
class InstanceofSimplifierVisitor extends BaseVisitor
{
    /** @var array<string, string> varName → className */
    private array $varTypes = [];

    #[\Override]
    public function beforeTraverse(array $nodes): ?array
    {
        parent::beforeTraverse($nodes);
        $this->varTypes = [];

        // Имена внутри namespace относительные: сравнивать их нельзя
        if ($this->findNode(Node\Stmt\Namespace_::class) !== []) {
            return null;
        }

        // Переменная, которой присваивают больше раза, может держать другой класс
        $assigned = [];
        foreach ($this->findNode(Node\Expr\Assign::class) as $assign) {
            if ($assign->var instanceof Node\Expr\Variable && is_string($assign->var->name)) {
                $assigned[$assign->var->name] = ($assigned[$assign->var->name] ?? 0) + 1;
            }
        }

        // Only scan top-level statements to stay within the same scope
        foreach ($nodes as $stmt) {
            if (!$stmt instanceof Node\Stmt\Expression) {
                continue;
            }

            $expr = $stmt->expr;
            if (
                $expr instanceof Node\Expr\Assign
                && $expr->var instanceof Node\Expr\Variable
                && is_string($expr->var->name)
                && $expr->expr instanceof Node\Expr\New_
                && $expr->expr->class instanceof Node\Name
                && $assigned[$expr->var->name] === 1
            ) {
                $this->varTypes[$expr->var->name] = $expr->expr->class->toString();
            }
        }

        return null;
    }

    #[\Override]
    public function leaveNode(Node $node): int|Node|array|null
    {
        if (
            $node instanceof Node\Expr\Instanceof_
            && $node->expr instanceof Node\Expr\Variable
            && is_string($node->expr->name)
            && $node->class instanceof Node\Name
        ) {
            $varName     = $node->expr->name;
            $targetClass = $node->class->toString();

            if (!isset($this->varTypes[$varName])) {
                return parent::leaveNode($node);
            }

            $isInstance = $this->isSubclassOf($this->varTypes[$varName], $targetClass);
            if ($isInstance === null) {
                return parent::leaveNode($node);
            }

            return new Node\Expr\ConstFetch(new Node\Name($isInstance ? 'true' : 'false'));
        }

        return parent::leaveNode($node);
    }

    /** null — иерархия известна не полностью. */
    private function isSubclassOf(string $childClass, string $parentClass): ?bool
    {
        $classes = [];
        foreach ($this->findNode(Node\Stmt\Class_::class) as $class) {
            if ($class->name instanceof Node\Identifier) {
                $classes[strtolower($class->name->toString())] = $class;
            }
        }

        $parent = strtolower(ltrim($parentClass, '\\'));
        $name   = strtolower(ltrim($childClass, '\\'));
        $seen   = [];
        while ($name !== $parent) {
            $class = $classes[$name] ?? null;
            if ($class === null || $class->implements !== [] || isset($seen[$name])) {
                return null;
            }

            if (!$class->extends instanceof Node\Name) {
                return false;
            }

            $seen[$name] = true;
            $name        = strtolower(ltrim($class->extends->toString(), '\\'));
        }

        return true;
    }
}
