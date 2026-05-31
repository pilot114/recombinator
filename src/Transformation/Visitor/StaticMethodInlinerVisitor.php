<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;
use PhpParser\NodeTraverser;

/**
 * Инлайнит статические методы классов на место вызовов.
 *
 * Обрабатывает методы с единственным `return`-выражением или единственным
 * `throw`-выражением (тело из одного оператора), чтобы результат оставался
 * выражением. Также заменяет `self`/`static` на конкретное имя класса.
 *
 * Алгоритм:
 *   1. Обходит AST и собирает все `static` методы из class-определений.
 *   2. При встрече `ClassName::method($args)` — заменяет на тело метода,
 *      подставляя аргументы вместо параметров.
 *
 * Запускать ПОСЛЕ ConstClassVisitor (константы уже раскрыты) и
 * ПОСЛЕ EnumCaseInlinerVisitor (enum-методы обработаны).
 */
#[VisitorMeta('Инлайн статических методов: ClassName::method() → тело метода')]
class StaticMethodInlinerVisitor extends BaseVisitor
{
    /**
     * @var array<string, array<string, Node\Stmt\ClassMethod>>
     */
    private array $staticMethods = [];

    #[\Override]
    public function beforeTraverse(array $nodes): ?array
    {
        parent::beforeTraverse($nodes);
        $this->staticMethods = [];
        $this->collectStaticMethods($nodes);
        return null;
    }

    /** @param array<Node> $nodes */
    private function collectStaticMethods(array $nodes): void
    {
        foreach ($nodes as $node) {
            if (!$node instanceof Node\Stmt\Class_) {
                continue;
            }

            if (!$node->name instanceof Node\Identifier) {
                continue;
            }

            $className = $node->name->name;
            foreach ($node->stmts as $stmt) {
                if (!$stmt instanceof Node\Stmt\ClassMethod) {
                    continue;
                }

                if (!$stmt->isStatic()) {
                    continue;
                }

                if (!$stmt->name instanceof Node\Identifier) {
                    continue;
                }

                if ($stmt->stmts === null) {
                    continue;
                }

                if (count($stmt->stmts) !== 1) {
                    continue;
                }

                $body = $stmt->stmts[0];
                // single return expr
                if ($body instanceof Node\Stmt\Return_ && $body->expr instanceof Node\Expr) {
                    $this->staticMethods[$className][$stmt->name->name] = $stmt;
                    continue;
                }

                // single throw expr (PHP 8: throw is an expression)
                if (
                    $body instanceof Node\Stmt\Expression
                    && $body->expr instanceof Node\Expr\Throw_
                ) {
                    $this->staticMethods[$className][$stmt->name->name] = $stmt;
                }
            }
        }
    }

    public function enterNode(Node $node): ?Node
    {
        if (
            !$node instanceof Node\Expr\StaticCall
            || !$node->class instanceof Node\Name
            || !$node->name instanceof Node\Identifier
        ) {
            return null;
        }

        $className  = $node->class->toString();
        $methodName = $node->name->name;

        if (!isset($this->staticMethods[$className][$methodName])) {
            return null;
        }

        $method = $this->staticMethods[$className][$methodName];
        $body   = $method->stmts[0];

        // Resolve the expression to inline: either return expr or throw expr
        if ($body instanceof Node\Stmt\Return_ && $body->expr instanceof Node\Expr) {
            $expr = $body->expr;
        } elseif ($body instanceof Node\Stmt\Expression && $body->expr instanceof Node\Expr\Throw_) {
            $expr = $body->expr;
        } else {
            return null;
        }

        // Build named-arg lookup
        $argByName = [];
        foreach ($node->args as $arg) {
            if ($arg instanceof Node\Arg && $arg->name instanceof Node\Identifier) {
                $argByName[$arg->name->toString()] = $arg->value;
            }
        }

        // Map params → call args
        $paramMap = [];
        foreach ($method->params as $i => $param) {
            if (!$param->var instanceof Node\Expr\Variable) {
                continue;
            }

            if (!is_string($param->var->name)) {
                continue;
            }

            $paramName = $param->var->name;
            if (isset($argByName[$paramName])) {
                $paramMap[$paramName] = clone $argByName[$paramName];
            } elseif (isset($node->args[$i]) && $node->args[$i] instanceof Node\Arg) {
                $paramMap[$paramName] = clone $node->args[$i]->value;
            } elseif ($param->default !== null) {
                $paramMap[$paramName] = clone $param->default;
            } else {
                $paramMap[$paramName] = new Node\Expr\ConstFetch(new Node\Name('null'));
            }
        }

        // Deep-clone and substitute parameters + resolve self/static to concrete class name
        $cloned    = $this->deepClone($expr);
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new class ($paramMap, $className) extends \PhpParser\NodeVisitorAbstract {
            /**
             * @param array<string, Node\Expr> $paramMap
             */
            public function __construct(
                private readonly array $paramMap,
                private readonly string $className,
            ) {
            }

            public function enterNode(Node $node): ?Node
            {
                if ($node instanceof Node\Expr\Variable && is_string($node->name) && isset($this->paramMap[$node->name])) {
                    return $this->paramMap[$node->name];
                }

                // Replace self/static → concrete class name
                if (
                    $node instanceof Node\Expr\New_
                    && $node->class instanceof Node\Name
                    && in_array($node->class->toString(), ['self', 'static'], true)
                ) {
                    $clone        = clone $node;
                    $clone->class = new Node\Name($this->className);
                    return $clone;
                }

                if (
                    ($node instanceof Node\Expr\StaticCall
                        || $node instanceof Node\Expr\StaticPropertyFetch
                        || $node instanceof Node\Expr\ClassConstFetch)
                    && $node->class instanceof Node\Name
                    && in_array($node->class->toString(), ['self', 'static'], true)
                ) {
                    $clone        = clone $node;
                    $clone->class = new Node\Name($this->className);
                    return $clone;
                }

                return null;
            }
        });

        $result = $traverser->traverse([$cloned]);
        return $result[0] instanceof Node\Expr ? $result[0] : null;
    }
}
