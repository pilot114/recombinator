<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;
use Recombinator\Transformation\BodyInliner;

/**
 * Отслеживает значения свойств объектов, назначенных на верхнем уровне,
 * и подставляет их в места чтения:
 *
 *   $whiskers->stepCounter = new Counter(42);
 *   $tmp = $whiskers->stepCounter?->value;   →   $tmp = (new Counter(42))?->value;
 *
 *   $shelter = new Shelter(city: 'Berlin');
 *   echo $shelter->city;                     →   echo 'Berlin';
 *
 * Работает только с присваиваниями на верхнем уровне файла (не внутри условий/циклов),
 * и только для позиций чтения (не LHS присваивания).
 *
 * Значение известно, только пока ни переменная, ни свойство не перезаписаны:
 * переменная должна присваиваться в своей области ровно один раз (иначе
 * `$c = $c->inc()` в цикле читал бы начальное значение), в свойство не должно быть
 * других записей. Подстановка — только в коде верхнего уровня: в функциях и методах
 * `$var` — другая переменная, а замыкание может выполниться позже записи.
 */
#[VisitorMeta('Подстановка значений свойств объектов из топ-уровневых присваиваний')]
class PropertyStateTrackerVisitor extends BaseVisitor
{
    /** @var array<string, array<string, Node\Expr>> varName → propName → expr */
    private array $propValues = [];

    /** Глубина вложенных функций, методов, замыканий и классов. */
    private int $scopeDepth = 0;

    #[\Override]
    public function beforeTraverse(array $nodes): ?array
    {
        parent::beforeTraverse($nodes);
        $this->propValues = [];
        $this->scopeDepth = 0;

        foreach ($nodes as $stmt) {
            if (!$stmt instanceof Node\Stmt\Expression) {
                continue;
            }

            $expr = $stmt->expr;

            // $obj->prop = expr  (explicit property assignment)
            if (
                $expr instanceof Node\Expr\Assign
                && $expr->var instanceof Node\Expr\PropertyFetch
                && $expr->var->var instanceof Node\Expr\Variable
                && is_string($expr->var->var->name)
                && $expr->var->name instanceof Node\Identifier
            ) {
                $varName  = $expr->var->var->name;
                $propName = $expr->var->name->toString();
                $this->propValues[$varName][$propName] = $expr->expr;
                continue;
            }

            // $obj = new ClassName(args)  — seed promoted-property values
            if (
                $expr instanceof Node\Expr\Assign
                && $expr->var instanceof Node\Expr\Variable
                && is_string($expr->var->name)
                && $expr->expr instanceof Node\Expr\New_
                && $expr->expr->class instanceof Node\Name
            ) {
                $varName   = $expr->var->name;
                $className = $expr->expr->class->toString();
                $this->seedConstructorProps($varName, $className, $expr->expr->args);
            }
        }

        $this->dropOverwritten($nodes);

        return null;
    }

    /**
     * Убирает переменные, присвоенные больше одного раза, и свойства, в которые
     * пишут не только в отслеживаемом присваивании.
     *
     * @param array<Node> $nodes
     */
    private function dropOverwritten(array $nodes): void
    {
        if ($this->propValues === []) {
            return;
        }

        foreach (ObjectMethodInlinerVisitor::topLevelAssignCounts($nodes) as $var => $count) {
            if ($count > 1) {
                unset($this->propValues[$var]);
            }
        }

        $propWrites = [];
        BodyInliner::walkOwnScope($nodes, static function (Node $node) use (&$propWrites): void {
            $target = match (true) {
                $node instanceof Node\Expr\Assign, $node instanceof Node\Expr\AssignOp, $node instanceof Node\Expr\AssignRef,
                $node instanceof Node\Expr\PreInc, $node instanceof Node\Expr\PreDec,
                $node instanceof Node\Expr\PostInc, $node instanceof Node\Expr\PostDec => $node->var,
                default => null,
            };
            while ($target instanceof Node\Expr\ArrayDimFetch) {
                $target = $target->var;
            }

            if (
                $target instanceof Node\Expr\PropertyFetch
                && $target->var instanceof Node\Expr\Variable
                && is_string($target->var->name)
                && $target->name instanceof Node\Identifier
            ) {
                $key              = $target->var->name . '->' . $target->name->toString();
                $propWrites[$key] = ($propWrites[$key] ?? 0) + ($node instanceof Node\Expr\Assign && $target === $node->var ? 1 : 2);
            }
        });

        foreach ($propWrites as $key => $count) {
            [$var, $prop] = explode('->', $key, 2);
            if ($count > 1) {
                unset($this->propValues[$var][$prop]);
            }
        }
    }

    #[\Override]
    public function enterNode(Node $node): int|Node|array|null
    {
        if ($this->isScope($node)) {
            $this->scopeDepth++;
        }

        return null;
    }

    private function isScope(Node $node): bool
    {
        return $node instanceof Node\Stmt\ClassLike
            || $node instanceof Node\Stmt\Function_
            || $node instanceof Node\Stmt\ClassMethod
            || $node instanceof Node\Expr\Closure;
    }

    /**
     * Seeds propValues from constructor-promoted properties so that
     * $var->prop reads can be replaced with the literal arg value.
     *
     * @param array<Node\Arg|Node\VariadicPlaceholder> $callArgs
     */
    private function seedConstructorProps(string $varName, string $className, array $callArgs): void
    {
        foreach ($this->findNode(Node\Stmt\Class_::class) as $class) {
            if (!$class->name instanceof Node\Identifier) {
                continue;
            }

            if ($class->name->toString() !== $className) {
                continue;
            }

            foreach ($class->getMethods() as $method) {
                if ($method->name->toString() !== '__construct') {
                    continue;
                }

                $paramToArg = $this->buildParamToArgMap($method->params, $callArgs);

                foreach ($method->params as $param) {
                    if ($param->flags === 0) {
                        continue; // not a promoted property
                    }

                    if (!$param->var instanceof Node\Expr\Variable || !is_string($param->var->name)) {
                        continue;
                    }

                    $propName = $param->var->name;
                    $argValue = $paramToArg[$propName] ?? null;
                    if ($argValue !== null) {
                        $this->propValues[$varName][$propName] = $argValue;
                    }
                }

                // Trace direct $this->prop = $param assignments in constructor body
                $this->traceBodyAssignments($varName, $method->stmts ?? [], $paramToArg, $class);
            }
        }
    }

    /**
     * Build a map of param name → actual arg expression.
     *
     * @param  Node\Param[]                              $params
     * @param  array<Node\Arg|Node\VariadicPlaceholder> $callArgs
     * @return array<string, Node\Expr>
     */
    private function buildParamToArgMap(array $params, array $callArgs): array
    {
        $map = [];
        foreach ($params as $i => $param) {
            if (!$param->var instanceof Node\Expr\Variable || !is_string($param->var->name)) {
                continue;
            }

            $paramName = $param->var->name;

            // Named arg first
            $argValue = null;
            foreach ($callArgs as $arg) {
                if (
                    $arg instanceof Node\Arg
                    && $arg->name instanceof Node\Identifier
                    && $arg->name->toString() === $paramName
                ) {
                    $argValue = $arg->value;
                    break;
                }
            }

            // Positional arg
            if (!$argValue instanceof \PhpParser\Node\Expr) {
                $arg = $callArgs[$i] ?? null;
                if ($arg instanceof Node\Arg && !$arg->name instanceof \PhpParser\Node\Identifier) {
                    $argValue = $arg->value;
                }
            }

            // Default
            if (!$argValue instanceof \PhpParser\Node\Expr) {
                $argValue = $param->default;
            }

            if ($argValue !== null) {
                $map[$paramName] = $argValue;
            }
        }

        return $map;
    }

    /**
     * Trace statements in a method body for direct $this->prop = $param assignments.
     * Also follows single-level $this->method(...) calls within the same class.
     *
     * @param  array<string, Node\Expr> $paramToArg
     */
    private function traceBodyAssignments(
        string $varName,
        array $stmts,
        array $paramToArg,
        Node\Stmt\Class_ $class,
        int $depth = 0,
    ): void {
        foreach ($stmts as $stmt) {
            if (!$stmt instanceof Node\Stmt\Expression) {
                continue;
            }

            $expr = $stmt->expr;

            // $this->prop = $param
            if (
                $expr instanceof Node\Expr\Assign
                && $expr->var instanceof Node\Expr\PropertyFetch
                && $expr->var->var instanceof Node\Expr\Variable
                && $expr->var->var->name === 'this'
                && $expr->var->name instanceof Node\Identifier
                && $expr->expr instanceof Node\Expr\Variable
                && is_string($expr->expr->name)
            ) {
                $propName  = $expr->var->name->toString();
                $paramName = $expr->expr->name;
                if (isset($paramToArg[$paramName])) {
                    $this->propValues[$varName][$propName] = $paramToArg[$paramName];
                }

                continue;
            }

            // $this->someMethod($arg1, $arg2, ...) — follow one level deep
            if (
                $depth === 0
                && $expr instanceof Node\Expr\MethodCall
                && $expr->var instanceof Node\Expr\Variable
                && $expr->var->name === 'this'
                && $expr->name instanceof Node\Identifier
            ) {
                $calledName = $expr->name->toString();
                foreach ($class->getMethods() as $method) {
                    if ($method->name->toString() !== $calledName) {
                        continue;
                    }

                    // Build arg map for the nested call (args are resolved via paramToArg)
                    $resolvedArgs = [];
                    foreach ($expr->args as $arg) {
                        if (!$arg instanceof Node\Arg) {
                            continue;
                        }

                        $val = $arg->value;
                        // Substitute known param variables
                        if ($val instanceof Node\Expr\Variable && is_string($val->name) && isset($paramToArg[$val->name])) {
                            $val = $paramToArg[$val->name];
                        }

                        $resolvedArgs[] = new Node\Arg($val, false, false, [], $arg->name);
                    }

                    $nestedParamToArg = $this->buildParamToArgMap($method->params, $resolvedArgs);
                    $this->traceBodyAssignments($varName, $method->stmts ?? [], $nestedParamToArg, $class, $depth + 1);
                }
            }
        }
    }

    #[\Override]
    public function leaveNode(Node $node): int|Node|array|null
    {
        if ($this->isScope($node)) {
            $this->scopeDepth--;
        }

        if (
            $this->scopeDepth === 0
            && ($node instanceof Node\Expr\PropertyFetch || $node instanceof Node\Expr\NullsafePropertyFetch)
            && $node->name instanceof Node\Identifier
        ) {
            // Resolve both $var->prop and (clone $var)->prop with the same known value
            if ($node->var instanceof Node\Expr\Variable && is_string($node->var->name)) {
                $varName = $node->var->name;
            } elseif (
                $node->var instanceof Node\Expr\Clone_
                && $node->var->expr instanceof Node\Expr\Variable
                && is_string($node->var->expr->name)
            ) {
                $varName = $node->var->expr->name;
            } else {
                return parent::leaveNode($node);
            }

            $propName = $node->name->toString();

            if (!isset($this->propValues[$varName][$propName])) {
                return parent::leaveNode($node);
            }

            // Skip if this PropertyFetch is the LHS of an Assign
            $parent = $node->getAttribute('parent');
            if ($parent instanceof Node\Expr\Assign && $parent->var === $node) {
                return parent::leaveNode($node);
            }

            return $this->deepClone($this->propValues[$varName][$propName]);
        }

        return parent::leaveNode($node);
    }
}
