<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;

/**
 * Заменяет доступ к свойствам readonly-класса через inline-конструктор на значение аргумента:
 *
 *   (new Counter(value: 42))->value          → 42
 *   (new Counter(value: 42))?->value         → 42  (New всегда non-null)
 *   (new Point(x: 3, y: 4))->x              → 3
 *
 * Применимо только к свойствам, продвинутым через конструктор (constructor promotion).
 */
#[VisitorMeta('Замена (new ReadonlyClass(args))->prop на значение аргумента')]
class ReadonlyPropertyAccessVisitor extends BaseVisitor
{
    /** @var array<string, array<string, int>> className → propName → constructorParamIndex */
    private array $promotedProps = [];

    #[\Override]
    public function beforeTraverse(array $nodes): ?array
    {
        parent::beforeTraverse($nodes);
        $this->promotedProps = [];

        foreach ($this->findNode(Node\Stmt\Class_::class) as $class) {
            if (($class->flags & Node\Stmt\Class_::MODIFIER_READONLY) === 0) {
                continue;
            }

            if (!$class->name instanceof Node\Identifier) {
                continue;
            }

            $className = $class->name->toString();
            $this->promotedProps[$className] = [];

            foreach ($class->getMethods() as $method) {
                if ($method->name->toString() !== '__construct') {
                    continue;
                }

                foreach ($method->params as $i => $param) {
                    if (
                        $param->flags !== 0
                        && $param->var instanceof Node\Expr\Variable
                        && is_string($param->var->name)
                    ) {
                        $this->promotedProps[$className][$param->var->name] = $i;
                    }
                }
            }
        }

        return null;
    }

    #[\Override]
    public function leaveNode(Node $node): int|Node|array|null
    {
        if (
            ($node instanceof Node\Expr\PropertyFetch || $node instanceof Node\Expr\NullsafePropertyFetch)
            && $node->name instanceof Node\Identifier
        ) {
            $new = $node->var;
            if (!($new instanceof Node\Expr\New_) || !($new->class instanceof Node\Name)) {
                return parent::leaveNode($node);
            }

            $className = $new->class->toString();
            $propName  = $node->name->toString();

            if (!isset($this->promotedProps[$className][$propName])) {
                return parent::leaveNode($node);
            }

            $paramIdx = $this->promotedProps[$className][$propName];

            // Try named arg first
            foreach ($new->args as $arg) {
                if (
                    $arg instanceof Node\Arg
                    && $arg->name instanceof Node\Identifier
                    && $arg->name->toString() === $propName
                ) {
                    return $this->deepClone($arg->value);
                }
            }

            // Then positional arg
            $arg = $new->args[$paramIdx] ?? null;
            if ($arg instanceof Node\Arg) {
                return $this->deepClone($arg->value);
            }
        }

        return parent::leaveNode($node);
    }
}
