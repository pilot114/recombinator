<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * Инлайнит простые методы объектов с известным типом:
 *
 *   $whiskers->getCount()   →   $whiskers->stepCounter?->value
 *   (string) $whiskers      →   $whiskers->__toString()
 *   $whiskers('jump')       →   $whiskers->__invoke('jump')
 *   ($obj->m(...))()        →   $obj->m()   (first-class callable → direct call)
 *
 * Условия:
 * - $var = new ClassName(...) известен на верхнем уровне
 * - Метод имеет ровно одну инструкцию: return expr;
 * - Класс должен присутствовать в AST
 *
 * Затем PropertyStateTrackerVisitor + ReadonlyPropertyAccessVisitor
 * разворачивают цепочку property-доступов до скаляра.
 */
#[VisitorMeta('Инлайн простых методов объектов с известным типом')]
class SimpleMethodCallInlinerVisitor extends BaseVisitor
{
    /** @var array<string, string> varName → className */
    private array $varTypes = [];

    private int $methodDepth = 0;

    #[\Override]
    public function beforeTraverse(array $nodes): ?array
    {
        parent::beforeTraverse($nodes);
        $this->varTypes   = [];
        $this->methodDepth = 0;

        foreach ($nodes as $stmt) {
            if (!$stmt instanceof Node\Stmt\Expression) {
                continue;
            }

            $assign = $stmt->expr;
            if (
                $assign instanceof Node\Expr\Assign
                && $assign->var instanceof Node\Expr\Variable
                && is_string($assign->var->name)
                && $assign->expr instanceof Node\Expr\New_
                && $assign->expr->class instanceof Node\Name
            ) {
                $this->varTypes[$assign->var->name] = $assign->expr->class->toString();
            }
        }

        return null;
    }

    #[\Override]
    public function enterNode(Node $node): int|Node|null
    {
        if ($node instanceof Node\Stmt\ClassMethod) {
            $this->methodDepth++;
        }

        return parent::enterNode($node);
    }

    #[\Override]
    public function leaveNode(Node $node): int|Node|array|null
    {
        if ($node instanceof Node\Stmt\ClassMethod) {
            $this->methodDepth--;
        }

        if ($this->methodDepth > 0) {
            return parent::leaveNode($node);
        }

        // (string) $var → $var->__toString() when $var is a known object
        if (
            $node instanceof Node\Expr\Cast\String_
            && $node->expr instanceof Node\Expr\Variable
            && is_string($node->expr->name)
            && isset($this->varTypes[$node->expr->name])
        ) {
            return new Node\Expr\MethodCall(
                new Node\Expr\Variable($node->expr->name),
                new Node\Identifier('__toString'),
            );
        }

        // $var($args) → $var->__invoke($args) when $var is a known object
        if (
            $node instanceof Node\Expr\FuncCall
            && $node->name instanceof Node\Expr\Variable
            && is_string($node->name->name)
            && isset($this->varTypes[$node->name->name])
        ) {
            return new Node\Expr\MethodCall(
                new Node\Expr\Variable($node->name->name),
                new Node\Identifier('__invoke'),
                $node->args,
            );
        }

        // ($obj->method(...))() → $obj->method()  (first-class callable immediately invoked)
        if (
            $node instanceof Node\Expr\FuncCall
            && $node->name instanceof Node\Expr\MethodCall
            && $node->args === []
            && count($node->name->args) === 1
            && $node->name->args[0] instanceof Node\VariadicPlaceholder
        ) {
            return new Node\Expr\MethodCall(
                $node->name->var,
                $node->name->name,
            );
        }

        if (!$node instanceof Node\Expr\MethodCall || !$node->name instanceof Node\Identifier) {
            return parent::leaveNode($node);
        }

        // Resolve the "self" variable: either $var directly or (clone $var)
        if ($node->var instanceof Node\Expr\Variable && is_string($node->var->name)) {
            $varName    = $node->var->name;
            $selfExpr   = $node->var;
        } elseif (
            $node->var instanceof Node\Expr\Clone_
            && $node->var->expr instanceof Node\Expr\Variable
            && is_string($node->var->expr->name)
        ) {
            $varName    = $node->var->expr->name; // look up class by original var
            $selfExpr   = $node->var;             // but substitute $this → (clone $origVar)
        } else {
            return parent::leaveNode($node);
        }

        $methodName = $node->name->toString();

        if (!isset($this->varTypes[$varName])) {
            return parent::leaveNode($node);
        }

        $method = $this->findMethod($this->varTypes[$varName], $methodName);
        if (!$method instanceof \PhpParser\Node\Stmt\ClassMethod) {
            return parent::leaveNode($node);
        }

        // Must be exactly one statement: return expr;
        if (
            $method->stmts === null
            || count($method->stmts) !== 1
            || !$method->stmts[0] instanceof Node\Stmt\Return_
            || !$method->stmts[0]->expr instanceof \PhpParser\Node\Expr
        ) {
            return parent::leaveNode($node);
        }

        // Don't inline methods that read $this->array[$key] — array property accesses
        // can't be resolved by scalar-tracking visitors and produce invalid PHP outside the class
        if ($this->bodyHasThisPropArrayAccess($method->stmts[0]->expr)) {
            return parent::leaveNode($node);
        }

        // Build param → arg map; null means args can't be resolved
        $paramToArg = $this->buildParamArgMap($method->params, $node->args);
        if ($paramToArg === null) {
            return parent::leaveNode($node);
        }

        return $this->substituteThisAndParams(
            $this->deepClone($method->stmts[0]->expr),
            $selfExpr,
            $paramToArg,
            $this->varTypes[$varName],
        );
    }

    /**
     * Returns true if the body contains $this->someArray[$key] — an array-indexed
     * property access that scalar-tracking visitors can't resolve. Inlining such a method
     * outside a class body would produce invalid (protected/private access) code that
     * nothing can simplify away.
     */
    private function bodyHasThisPropArrayAccess(Node\Expr $body): bool
    {
        return array_any($this->findNode(Node\Expr\ArrayDimFetch::class, [$body]), fn($fetch): bool => $fetch->var instanceof Node\Expr\PropertyFetch
        && $fetch->var->var instanceof Node\Expr\Variable
        && $fetch->var->var->name === 'this');
    }

    private function findMethod(string $className, string $methodName): ?Node\Stmt\ClassMethod
    {
        foreach ($this->findNode(Node\Stmt\Class_::class) as $class) {
            if (
                $class->name instanceof Node\Identifier
                && $class->name->toString() === $className
            ) {
                foreach ($class->getMethods() as $method) {
                    if ($method->name->toString() === $methodName) {
                        return $method;
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param  Node\Param[]                              $params
     * @param  array<Node\Arg|Node\VariadicPlaceholder>  $callArgs
     * @return array<string, Node\Expr>|null  null = can't resolve (e.g. variadic spread)
     */
    private function buildParamArgMap(array $params, array $callArgs): ?array
    {
        // Bail on variadic spread args in the call
        foreach ($callArgs as $arg) {
            if ($arg instanceof Node\VariadicPlaceholder) {
                return null;
            }
        }

        $map = [];
        foreach ($params as $i => $param) {
            if (!$param->var instanceof Node\Expr\Variable || !is_string($param->var->name)) {
                return null;
            }

            if ($param->variadic) {
                return null; // don't inline variadic params
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

            if ($argValue === null) {
                return null;
            }

            $map[$paramName] = $argValue;
        }

        return $map;
    }

    /**
     * @param array<string, Node\Expr> $paramToArg
     */
    private function substituteThisAndParams(
        Node\Expr $expr,
        Node\Expr $selfExpr,
        array $paramToArg,
        string $className = '',
    ): Node\Expr {
        $deepClone = fn(Node $n): Node => $this->deepClone($n);

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new class ($selfExpr, $paramToArg, $deepClone, $className) extends NodeVisitorAbstract {
            /** @param array<string, Node\Expr> $paramToArg */
            public function __construct(
                private readonly Node\Expr $selfExpr,
                private readonly array $paramToArg,
                /** @var callable(Node): Node */
                private readonly \Closure $deepClone,
                private readonly string $className,
            ) {
            }

            public function leaveNode(Node $node): ?Node
            {
                // $this → selfExpr (either $var or clone $var)
                if ($node instanceof Node\Expr\Variable && $node->name === 'this') {
                    return ($this->deepClone)($this->selfExpr);
                }

                // $paramName → argExpr
                if (
                    $node instanceof Node\Expr\Variable
                    && is_string($node->name)
                    && isset($this->paramToArg[$node->name])
                ) {
                    return ($this->deepClone)($this->paramToArg[$node->name]);
                }

                // self → ConcreteClassName  (so `new self(...)` becomes `new ConcreteClass(...)`)
                if (
                    $this->className !== ''
                    && $node instanceof Node\Name
                    && $node->toString() === 'self'
                ) {
                    return new Node\Name($this->className);
                }

                return null;
            }
        });

        $result = $traverser->traverse([$expr]);
        return $result[0];
    }
}
