<?php

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * Инлайнинг классов:
 * - Определение класса удаляется из AST
 * - new ClassName($args)  →  набор переменных для полей + инлайн конструктора
 * - $obj->method($args)   →  тело метода (single-return) с подстановкой аргументов
 * - $obj->prop            →  переменная поля  (e.g. $auth__test)
 *
 * Запускать ПОСЛЕ TernarReturnVisitor, чтобы методы вида
 *   if(cond) { return a; } return b;
 * уже были сведены к  return cond ? a : b  и стали однострочными.
 */
#[VisitorMeta('Удаление классов: new X() → переменные полей; $obj->m() → тело метода; $obj->p → переменная')]
class ClassInlinerVisitor extends BaseVisitor
{
    /**
     * @var array<string, array{props: array<string, Node\Expr|null>, methods: array<string, Node\Stmt\ClassMethod>}>
     */
    private array $classRegistry = [];

    /**
     * @var array<string, array{className: string, propVars: array<string, string>}>
     */
    private array $instanceRegistry = [];

    public function enterNode(Node $node): int|Node|array|null
    {
        // 1. Collect class definition and mark for removal
        if ($node instanceof Node\Stmt\Class_ && $node->name instanceof \PhpParser\Node\Identifier) {
            $this->collectClass($node);
            $node->setAttribute('remove', true);
            return null;
        }

        // 2. $var = new ClassName($args);
        if (
            $node instanceof Node\Stmt\Expression
            && $node->expr instanceof Node\Expr\Assign
            && $node->expr->var instanceof Node\Expr\Variable
            && is_string($node->expr->var->name)
            && $node->expr->expr instanceof Node\Expr\New_
        ) {
            $stmts = $this->processNew($node->expr->var->name, $node->expr->expr);
            if ($stmts !== null) {
                $node->setAttribute('replace', $stmts);
            }

            return null;
        }

        // 3. $obj->method($args)
        if (
            $node instanceof Node\Expr\MethodCall
            && $node->var instanceof Node\Expr\Variable
            && is_string($node->var->name)
            && $node->name instanceof Node\Identifier
        ) {
            return $this->processMethodCall($node);
        }

        // 4. $obj->prop
        if (
            $node instanceof Node\Expr\PropertyFetch
            && $node->var instanceof Node\Expr\Variable
            && is_string($node->var->name)
            && $node->name instanceof Node\Identifier
        ) {
            return $this->processPropertyFetch($node->var->name, $node->name->name);
        }

        return null;
    }

    private function collectClass(Node\Stmt\Class_ $class): void
    {
        $name = $class->name->name;
        $props = [];
        $methods = [];

        foreach ($class->stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\Property) {
                foreach ($stmt->props as $prop) {
                    if ($prop instanceof Node\PropertyItem) {
                        $props[$prop->name->name] = $prop->default;
                    }
                }
            }

            if ($stmt instanceof Node\Stmt\ClassMethod) {
                $methods[$stmt->name->name] = $stmt;
            }
        }

        $this->classRegistry[$name] = [
            'props'   => $props,
            'methods' => $methods,
        ];
    }

    /**
     * @param array<Node\Arg> $args
     * @return array<Node\Stmt>|null
     */
    private function processNew(string $varName, Node\Expr\New_ $new): ?array
    {
        if (!($new->class instanceof Node\Name)) {
            return null;
        }

        $className = $new->class->toString();
        if (!isset($this->classRegistry[$className])) {
            return null;
        }

        $class    = $this->classRegistry[$className];
        $propVars = [];
        $stmts    = [];

        // Generate property variables: $varName__propName = defaultValue
        foreach ($class['props'] as $propName => $default) {
            $generatedName       = $varName . '__' . $propName;
            $propVars[$propName] = $generatedName;

            $value    = $default ?? new Node\Expr\ConstFetch(new Node\Name('null'));
            $stmts[]  = new Node\Stmt\Expression(
                new Node\Expr\Assign(
                    new Node\Expr\Variable($generatedName),
                    clone $value
                )
            );
        }

        // Inline constructor body if present
        if (isset($class['methods']['__construct'])) {
            $ctorStmts = $this->inlineMethodStmts(
                $class['methods']['__construct'],
                $new->args,
                $propVars
            );
            $stmts = array_merge($stmts, $ctorStmts);
        }

        $this->instanceRegistry[$varName] = [
            'className' => $className,
            'propVars'  => $propVars,
        ];

        return $stmts;
    }

    private function processMethodCall(Node\Expr\MethodCall $node): ?Node\Expr
    {
        $varName = $node->var->name;
        if (!isset($this->instanceRegistry[$varName])) {
            return null;
        }

        ['className' => $className, 'propVars' => $propVars] = $this->instanceRegistry[$varName];

        $methodName = $node->name->name;
        if (!isset($this->classRegistry[$className]['methods'][$methodName])) {
            return null;
        }

        $method = $this->classRegistry[$className]['methods'][$methodName];
        return $this->inlineMethodExpr($method, $node->args, $propVars);
    }

    private function processPropertyFetch(string $varName, string $propName): ?Node\Expr
    {
        if (!isset($this->instanceRegistry[$varName])) {
            return null;
        }

        $propVars = $this->instanceRegistry[$varName]['propVars'];
        return isset($propVars[$propName]) ? new Node\Expr\Variable($propVars[$propName]) : null;
    }

    /**
     * Inline a single-return method as an expression.
     *
     * @param array<Node\Arg>       $callArgs
     * @param array<string, string> $propVars
     */
    private function inlineMethodExpr(
        Node\Stmt\ClassMethod $method,
        array $callArgs,
        array $propVars
    ): ?Node\Expr {
        $stmts = $method->stmts;
        if ($stmts === null || count($stmts) !== 1 || !($stmts[0] instanceof Node\Stmt\Return_)) {
            return null;
        }

        $expr = $stmts[0]->expr;
        if (!($expr instanceof Node\Expr)) {
            return null;
        }

        // Deep clone so repeated calls don't mutate the stored ClassMethod body.
        $traverser = new NodeTraverser();
        $traverser->addVisitor($this->makeSubstitutor($method->params, $callArgs, $propVars));
        [$result] = $traverser->traverse([$this->deepClone($expr)]);
        return $result;
    }

    /**
     * Inline a multi-statement method body (used for constructors).
     *
     * @param array<Node\Arg>       $callArgs
     * @param array<string, string> $propVars
     * @return array<Node\Stmt>
     */
    private function inlineMethodStmts(
        Node\Stmt\ClassMethod $method,
        array $callArgs,
        array $propVars
    ): array {
        $result = [];
        foreach ($method->stmts ?? [] as $stmt) {
            $traverser = new NodeTraverser();
            $traverser->addVisitor($this->makeSubstitutor($method->params, $callArgs, $propVars));
            [$cloned] = $traverser->traverse([$this->deepClone($stmt)]);
            $result[] = $cloned;
        }

        return $result;
    }

    /**
     * Recursively clone a node and all its child nodes.
     */
    private function deepClone(Node $node): Node
    {
        $cloned = clone $node;
        foreach ($cloned->getSubNodeNames() as $subName) {
            $value = $cloned->$subName;
            if ($value instanceof Node) {
                $cloned->$subName = $this->deepClone($value);
            } elseif (is_array($value)) {
                foreach ($value as $k => $item) {
                    if ($item instanceof Node) {
                        $value[$k] = $this->deepClone($item);
                    }
                }

                $cloned->$subName = $value;
            }
        }

        return $cloned;
    }

    /**
     * Build a visitor that substitutes parameter variables and $this->prop.
     *
     * @param array<Node\Param>     $params
     * @param array<Node\Arg>       $callArgs
     * @param array<string, string> $propVars  propName → generatedVarName
     */
    private function makeSubstitutor(array $params, array $callArgs, array $propVars): NodeVisitorAbstract
    {
        $paramMap = [];
        foreach ($params as $i => $param) {
            if (!($param->var instanceof Node\Expr\Variable)) {
                continue;
            }

            if (!is_string($param->var->name)) {
                continue;
            }

            $paramName = $param->var->name;
            if (isset($callArgs[$i])) {
                $paramMap[$paramName] = clone $callArgs[$i]->value;
            } elseif ($param->default !== null) {
                $paramMap[$paramName] = clone $param->default;
            } else {
                $paramMap[$paramName] = new Node\Expr\ConstFetch(new Node\Name('null'));
            }
        }

        return new class ($paramMap, $propVars) extends NodeVisitorAbstract {
            /**
             * @param array<string, Node\Expr> $paramMap
             * @param array<string, string>    $propVars
             */
            public function __construct(
                private readonly array $paramMap,
                private readonly array $propVars,
            ) {
            }

            public function enterNode(Node $node): ?Node
            {
                // Replace parameter variables: $param → arg value
                if ($node instanceof Node\Expr\Variable && is_string($node->name) && isset($this->paramMap[$node->name])) {
                    return $this->paramMap[$node->name];
                }

                // Replace $this->prop → $generatedVarName
                if (
                    $node instanceof Node\Expr\PropertyFetch
                    && $node->var instanceof Node\Expr\Variable
                    && $node->var->name === 'this'
                    && $node->name instanceof Node\Identifier
                ) {
                    $propName = $node->name->name;
                    if (isset($this->propVars[$propName])) {
                        return new Node\Expr\Variable($this->propVars[$propName]);
                    }
                }

                return null;
            }
        };
    }
}
