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
 * Тип переменной берётся из ближайшего прямого присваивания `$var = new ClassName(...)`.
 * Проверяется иерархия классов через цепочку extends в текущем AST.
 */
#[VisitorMeta('Упрощение instanceof с известным типом переменной')]
class InstanceofSimplifierVisitor extends BaseVisitor
{
    /** @var array<string, string> varName → className */
    private array $varTypes = [];

    #[\Override]
    public function beforeTraverse(array $nodes): ?array
    {
        parent::beforeTraverse($nodes);
        $this->varTypes = [];

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

            $instanceClass = $this->varTypes[$varName];
            $isInstance    = $this->isSubclassOf($instanceClass, $targetClass);

            return new Node\Expr\ConstFetch(new Node\Name($isInstance ? 'true' : 'false'));
        }

        return parent::leaveNode($node);
    }

    private function isSubclassOf(string $childClass, string $parentClass): bool
    {
        if ($childClass === $parentClass) {
            return true;
        }

        foreach ($this->findNode(Node\Stmt\Class_::class) as $class) {
            if (
                $class->name instanceof Node\Identifier
                && $class->name->toString() === $childClass
                && $class->extends instanceof Node\Name
            ) {
                return $this->isSubclassOf($class->extends->toString(), $parentClass);
            }
        }

        return false;
    }
}
