<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;

/**
 * Отслеживает мутирующие вызовы методов вида $this->prop += $param
 * и подставляет вычисленное значение свойства в последующий код.
 *
 *   $rex = new SyntaxZoo_Dog(...);    // stepCount начинается с 0
 *   $rex->track(steps: 3);            // stepCount = 0 + 3 = 3   ← удаляется
 *   $rex->stepCount >= 25             // → 3 >= 25 → false
 *
 * Условия:
 * - Метод содержит ровно один оператор: compound-assign ($this->prop OP= $param)
 * - Переменная объявлена на верхнем уровне через $var = new ClassName(...)
 * - Вызов метода — тоже на верхнем уровне (Stmt\Expression)
 */
#[VisitorMeta('Отслеживание мутирующих вызовов $this->prop += $param')]
class MutablePropertyTrackerVisitor extends BaseVisitor
{
    /** @var array<string, array<string, mixed>> varName → propName → currentValue (scalar PHP) */
    private array $state = [];

    /** @var array<string, string> varName → className */
    private array $varToClass = [];

    /**
     * className → methodName → [prop, op, paramIndex, paramName, default]
     * @var array<string, array<string, array{prop: string, op: string, paramIndex: int, paramName: string, default: mixed}>>
     */
    private array $effectMethods = [];

    #[\Override]
    public function beforeTraverse(array $nodes): ?array
    {
        parent::beforeTraverse($nodes);
        $this->state         = [];
        $this->varToClass    = [];
        $this->effectMethods = [];

        // Collect effect methods and initial property values per class
        /** @var array<string, array<string, mixed>> */
        $initialProps = [];

        foreach ($this->findNode(Node\Stmt\Class_::class) as $class) {
            if (!$class->name instanceof Node\Identifier) {
                continue;
            }

            $className = $class->name->toString();

            // Initial values from property declarations
            foreach ($class->getProperties() as $property) {
                if (($property->flags & \PhpParser\Modifiers::STATIC) !== 0) {
                    continue;
                }

                foreach ($property->props as $prop) {
                    if (!$prop->name instanceof Node\Identifier) {
                        continue;
                    }

                    $scalar = $this->toScalar($prop->default);
                    if ($scalar !== null) {
                        $initialProps[$className][$prop->name->name] = $scalar;
                    }
                }
            }

            // Effect methods: single AssignOp on $this->prop
            foreach ($class->getMethods() as $method) {
                if ($method->stmts === null) {
                    continue;
                }

                if (count($method->stmts) !== 1) {
                    continue;
                }

                $stmt = $method->stmts[0];
                if (!$stmt instanceof Node\Stmt\Expression) {
                    continue;
                }

                $expr = $stmt->expr;
                if (!($expr instanceof Node\Expr\AssignOp)) {
                    continue;
                }

                if (!$expr->var instanceof Node\Expr\PropertyFetch) {
                    continue;
                }

                if (!$expr->var->var instanceof Node\Expr\Variable) {
                    continue;
                }

                if ($expr->var->var->name !== 'this') {
                    continue;
                }

                if (!$expr->var->name instanceof Node\Identifier) {
                    continue;
                }

                if (!$expr->expr instanceof Node\Expr\Variable) {
                    continue;
                }

                if (!is_string($expr->expr->name)) {
                    continue;
                }

                $propName  = $expr->var->name->toString();
                $paramVar  = $expr->expr->name;
                $op        = $expr::class;

                foreach ($method->params as $i => $param) {
                    if (
                        $param->var instanceof Node\Expr\Variable
                        && $param->var->name === $paramVar
                    ) {
                        $defaultVal = $this->toScalar($param->default);
                        $this->effectMethods[$className][$method->name->toString()] = [
                            'prop'       => $propName,
                            'op'         => $op,
                            'paramIndex' => $i,
                            'paramName'  => $paramVar,
                            'default'    => $defaultVal,
                        ];
                        break;
                    }
                }
            }
        }

        // Initialize state for variables declared at top level via $var = new ClassName(...)
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
                $varName   = $expr->var->name;
                $className = $expr->expr->class->toString();
                $this->varToClass[$varName] = $className;
                if (isset($initialProps[$className])) {
                    $this->state[$varName] = $initialProps[$className];
                }
            }
        }

        return null;
    }

    #[\Override]
    public function enterNode(Node $node): int|Node|null
    {
        // Substitute $var->prop with tracked value (enterNode so subsequent leaveNode sees the scalar)
        if (
            $node instanceof Node\Expr\PropertyFetch
            && $node->var instanceof Node\Expr\Variable
            && is_string($node->var->name)
            && $node->name instanceof Node\Identifier
        ) {
            $varName  = $node->var->name;
            $propName = $node->name->toString();

            if (isset($this->state[$varName][$propName])) {
                return $this->valueToNode($this->state[$varName][$propName]);
            }
        }

        return parent::enterNode($node);
    }

    #[\Override]
    public function leaveNode(Node $node): int|Node|array|null
    {
        // Detect $var->method(args) top-level calls that mutate state
        if (
            $node instanceof Node\Stmt\Expression
            && $node->expr instanceof Node\Expr\MethodCall
        ) {
            $call = $node->expr;
            if (
                $call->var instanceof Node\Expr\Variable
                && is_string($call->var->name)
                && $call->name instanceof Node\Identifier
            ) {
                $varName    = $call->var->name;
                $methodName = $call->name->toString();
                $className  = $this->varToClass[$varName] ?? null;

                if ($className !== null && isset($this->effectMethods[$className][$methodName])) {
                    $effect = $this->effectMethods[$className][$methodName];

                    // Resolve the argument value
                    $argValue = null;
                    foreach ($call->args as $arg) {
                        if (
                            $arg instanceof Node\Arg
                            && $arg->name instanceof Node\Identifier
                            && $arg->name->toString() === $effect['paramName']
                        ) {
                            $argValue = $this->toScalar($arg->value);
                            break;
                        }
                    }

                    if ($argValue === null) {
                        $arg = $call->args[$effect['paramIndex']] ?? null;
                        if ($arg instanceof Node\Arg && !$arg->name instanceof \PhpParser\Node\Identifier) {
                            $argValue = $this->toScalar($arg->value);
                        }
                    }

                    if ($argValue === null) {
                        $argValue = $effect['default'];
                    }

                    if (is_numeric($argValue)) {
                        $current  = $this->state[$varName][$effect['prop']] ?? 0;
                        $newValue = match ($effect['op']) {
                            Node\Expr\AssignOp\Plus::class  => $current + $argValue,
                            Node\Expr\AssignOp\Minus::class => $current - $argValue,
                            Node\Expr\AssignOp\Mul::class   => $current * $argValue,
                            default                         => null,
                        };
                        if ($newValue !== null) {
                            $this->state[$varName][$effect['prop']] = $newValue;
                            $node->setAttribute('remove', true);
                        }
                    }
                }
            }
        }

        return parent::leaveNode($node);
    }

    private function toScalar(mixed $node): mixed
    {
        if ($node instanceof Node\Scalar\LNumber) {
            return $node->value;
        }

        if ($node instanceof Node\Scalar\DNumber) {
            return $node->value;
        }

        if ($node instanceof Node\Scalar\String_) {
            return $node->value;
        }

        if ($node instanceof Node\Expr\ConstFetch) {
            return match (strtolower($node->name->toString())) {
                'true'  => true,
                'false' => false,
                'null'  => null,
                default => null,
            };
        }

        return null;
    }

    private function valueToNode(mixed $value): Node\Expr
    {
        if (is_int($value)) {
            return new Node\Scalar\LNumber($value);
        }

        if (is_float($value)) {
            return new Node\Scalar\DNumber($value);
        }

        if (is_string($value)) {
            return new Node\Scalar\String_($value);
        }

        if (is_bool($value)) {
            return new Node\Expr\ConstFetch(new Node\Name($value ? 'true' : 'false'));
        }

        return new Node\Expr\ConstFetch(new Node\Name('null'));
    }
}
