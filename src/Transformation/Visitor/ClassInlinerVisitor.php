<?php

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use Recombinator\Core\Config;

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

    /**
     * Classes whose every instance is used only via ->prop / ->method() accesses
     * and thus can be safely inlined away.
     *
     * @var array<string, true>
     */
    private array $safeClasses = [];

    private readonly Config $config;

    public function __construct(?Config $config = null)
    {
        $this->config = $config ?? new Config();
    }

    #[\Override]
    public function beforeTraverse(array $nodes): ?array
    {
        parent::beforeTraverse($nodes);
        $this->safeClasses = [];

        // Build varName → className map from $var = new ClassName(...) patterns
        /** @var array<string, string> $varToClass */
        $varToClass = [];
        foreach ($this->findNode(Node\Stmt\Expression::class, $nodes) as $stmt) {
            if (
                $stmt instanceof Node\Stmt\Expression
                && $stmt->expr instanceof Node\Expr\Assign
                && $stmt->expr->var instanceof Node\Expr\Variable
                && is_string($stmt->expr->var->name)
                && $stmt->expr->expr instanceof Node\Expr\New_
                && $stmt->expr->expr->class instanceof Node\Name
            ) {
                $varToClass[$stmt->expr->var->name] = $stmt->expr->expr->class->toString();
            }
        }

        if ($varToClass === []) {
            return null;
        }

        // Count total occurrences of each tracked variable name.
        /** @var array<string, int> $totalCount */
        $totalCount = [];
        foreach ($this->findNode(Node\Expr\Variable::class, $nodes) as $var) {
            if (is_string($var->name) && isset($varToClass[$var->name])) {
                $totalCount[$var->name] = ($totalCount[$var->name] ?? 0) + 1;
            }
        }

        // Count occurrences in "safe" positions:
        // (a) $var->prop  (b) $var->method()  (c) $var = expr (Assign LHS)
        /** @var array<string, int> $safeCount */
        $safeCount = [];
        foreach ($this->findNode(Node\Expr\PropertyFetch::class, $nodes) as $fetch) {
            if ($fetch->var instanceof Node\Expr\Variable && is_string($fetch->var->name) && isset($varToClass[$fetch->var->name])) {
                $safeCount[$fetch->var->name] = ($safeCount[$fetch->var->name] ?? 0) + 1;
            }
        }

        foreach ($this->findNode(Node\Expr\MethodCall::class, $nodes) as $call) {
            if ($call->var instanceof Node\Expr\Variable && is_string($call->var->name) && isset($varToClass[$call->var->name])) {
                $safeCount[$call->var->name] = ($safeCount[$call->var->name] ?? 0) + 1;
            }
        }

        foreach ($this->findNode(Node\Expr\Assign::class, $nodes) as $assign) {
            if ($assign->var instanceof Node\Expr\Variable && is_string($assign->var->name) && isset($varToClass[$assign->var->name])) {
                $safeCount[$assign->var->name] = ($safeCount[$assign->var->name] ?? 0) + 1;
            }
        }

        // A variable is safe iff every occurrence is in a safe position.
        // A class is safe iff ALL its instances are safe.
        /** @var array<string, true> $unsafeClasses */
        $unsafeClasses = [];
        foreach ($varToClass as $varName => $className) {
            $total = $totalCount[$varName] ?? 0;
            $safe  = $safeCount[$varName] ?? 0;
            if ($total !== $safe) {
                $unsafeClasses[$className] = true;
            }
        }

        // A class is also unsafe if it has standalone `new ClassName(...)` expressions
        // that are NOT in `$var = new ClassName(...)` form (e.g. inside array literals,
        // function args, property assignments). Count all New_ nodes per class name and
        // compare against how many are accounted for in $varToClass assignments.
        /** @var array<string, int> $assignedNewCount */
        $assignedNewCount = array_count_values($varToClass);
        /** @var array<string, int> $totalNewCount */
        $totalNewCount = [];
        foreach ($this->findNode(Node\Expr\New_::class, $nodes) as $new) {
            if ($new->class instanceof Node\Name) {
                $cn = $new->class->toString();
                if (isset($assignedNewCount[$cn])) {
                    $totalNewCount[$cn] = ($totalNewCount[$cn] ?? 0) + 1;
                }
            }
        }

        foreach ($assignedNewCount as $cn => $assignedCount) {
            if (($totalNewCount[$cn] ?? 0) > $assignedCount) {
                $unsafeClasses[$cn] = true;
            }
        }

        foreach ($varToClass as $className) {
            if (!isset($unsafeClasses[$className])) {
                $this->safeClasses[$className] = true;
            }
        }

        // Build a temporary method registry from the AST to pre-check inlinability.
        // Classes whose instances have any non-single-return method call must stay
        // in the AST (inlining would be partial), so they are removed from safeClasses.
        $tempMethodRegistry = [];
        foreach ($this->findNode(Node\Stmt\Class_::class, $nodes) as $class) {
            if (!$class->name instanceof Node\Identifier) {
                continue;
            }

            $classMethods = [];
            foreach ($class->stmts as $stmt) {
                if ($stmt instanceof Node\Stmt\ClassMethod) {
                    $classMethods[$stmt->name->name] = $stmt;
                }
            }

            $tempMethodRegistry[$class->name->name] = $classMethods;
        }

        foreach ($varToClass as $varName => $className) {
            if (!isset($this->safeClasses[$className])) {
                continue;
            }

            $classMethods = $tempMethodRegistry[$className] ?? [];
            foreach ($this->findNode(Node\Expr\MethodCall::class, $nodes) as $call) {
                if (!($call->var instanceof Node\Expr\Variable)) {
                    continue;
                }

                if ($call->var->name !== $varName) {
                    continue;
                }

                if (!($call->name instanceof Node\Identifier)) {
                    continue;
                }

                $methodName = $call->name->name;
                $method     = $classMethods[$methodName] ?? null;
                if (!$method instanceof \PhpParser\Node\Stmt\ClassMethod) {
                    continue;
                }

                $stmts = $method->stmts;
                if ($stmts === null || count($stmts) !== 1 || !($stmts[0] instanceof Node\Stmt\Return_)) {
                    unset($this->safeClasses[$className]);
                    break;
                }
            }
        }

        return null;
    }

    public function enterNode(Node $node): int|Node|array|null
    {
        // 1. Collect class definition and mark for removal.
        //    Классы из публичного контракта не инлайним и не удаляем —
        //    их определение должно остаться доступным для внешних вызовов.
        //    Классы, экземпляры которых используются в небезопасных контекстах
        //    (массивы, аргументы функций и т.д.) — тоже не удаляем.
        if ($node instanceof Node\Stmt\Class_ && $node->name instanceof \PhpParser\Node\Identifier) {
            if ($this->config->isProtected($node->name->name)) {
                return null;
            }

            $this->collectClass($node);
            if (isset($this->safeClasses[$node->name->name])) {
                $node->setAttribute('remove', true);
            }

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
        $name            = $class->name->name;
        $props           = [];
        $promotedParams  = [];
        $methods         = [];

        foreach ($class->stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\Property) {
                if (($stmt->flags & \PhpParser\Modifiers::STATIC) !== 0) {
                    continue;
                }

                foreach ($stmt->props as $prop) {
                    if ($prop instanceof Node\PropertyItem) {
                        $props[$prop->name->name] = $prop->default;
                    }
                }
            }

            if ($stmt instanceof Node\Stmt\ClassMethod) {
                $methods[$stmt->name->name] = $stmt;
                if ($stmt->name->name === '__construct') {
                    foreach ($stmt->params as $i => $param) {
                        if (
                            $param->flags !== 0
                            && $param->var instanceof Node\Expr\Variable
                            && is_string($param->var->name)
                        ) {
                            $propName = $param->var->name;
                            if (!isset($props[$propName])) {
                                $props[$propName] = $param->default;
                            }

                            $promotedParams[$propName] = $i;
                        }
                    }
                }
            }
        }

        $this->classRegistry[$name] = [
            'props'          => $props,
            'promotedParams' => $promotedParams,
            'methods'        => $methods,
        ];
    }

    /**
     * Returns true if every MethodCall on $varName in the entire AST references
     * a single-return method that ClassInliner can actually substitute inline.
     * Multi-statement or generator methods can't be inlined, so the class is
     * not fully inlinable and must be left intact.
     */
    private function allMethodCallsInlinable(string $varName, string $className): bool
    {
        $classData = $this->classRegistry[$className] ?? null;
        if ($classData === null) {
            return false;
        }

        foreach ($this->findNode(Node\Expr\MethodCall::class) as $call) {
            if (!($call->var instanceof Node\Expr\Variable)) {
                continue;
            }

            if ($call->var->name !== $varName) {
                continue;
            }

            if (!($call->name instanceof Node\Identifier)) {
                continue;
            }

            $methodName = $call->name->name;
            $method     = $classData['methods'][$methodName] ?? null;
            if ($method === null) {
                continue;
            }

            $stmts = $method->stmts;
            if ($stmts === null || count($stmts) !== 1 || !($stmts[0] instanceof Node\Stmt\Return_)) {
                return false;
            }
        }

        return true;
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
        if (!isset($this->classRegistry[$className]) || !isset($this->safeClasses[$className])) {
            return null;
        }

        if (!$this->allMethodCallsInlinable($varName, $className)) {
            return null;
        }

        $class          = $this->classRegistry[$className];
        $propVars       = [];
        $stmts          = [];
        $promotedParams = $class['promotedParams'] ?? [];

        // Named-arg lookup for promoted parameter resolution
        $argByName = [];
        foreach ($new->args as $arg) {
            if ($arg instanceof Node\Arg && $arg->name instanceof Node\Identifier) {
                $argByName[$arg->name->toString()] = $arg->value;
            }
        }

        // Generate property variables
        foreach ($class['props'] as $propName => $default) {
            $generatedName       = $varName . '__' . $propName;
            $propVars[$propName] = $generatedName;

            // Promoted parameter: resolve from call args (named → positional → default)
            if (isset($promotedParams[$propName])) {
                $idx = $promotedParams[$propName];
                $value = $argByName[$propName]
                    ?? (isset($new->args[$idx]) && $new->args[$idx] instanceof Node\Arg ? $new->args[$idx]->value : null)
                    ?? $default
                    ?? new Node\Expr\ConstFetch(new Node\Name('null'));
            } else {
                $value = $default ?? new Node\Expr\ConstFetch(new Node\Name('null'));
            }

            $stmts[] = new Node\Stmt\Expression(
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
                $propVars,
                $class['methods'],
                0,
                $className
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
        return $this->inlineMethodExpr($method, $node->args, $propVars, $className);
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
        array $propVars,
        string $className = ''
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
        $traverser->addVisitor($this->makeSubstitutor($method->params, $callArgs, $propVars, $className));
        [$result] = $traverser->traverse([$this->deepClone($expr)]);
        return $result;
    }

    /**
     * Inline a multi-statement method body (used for constructors).
     * Recursively inlines $this->method(args) void calls when classMethods are provided.
     *
     * @param array<Node\Arg>                     $callArgs
     * @param array<string, string>               $propVars
     * @param array<string, Node\Stmt\ClassMethod> $classMethods
     * @return array<Node\Stmt>
     */
    private function inlineMethodStmts(
        Node\Stmt\ClassMethod $method,
        array $callArgs,
        array $propVars,
        array $classMethods = [],
        int $depth = 0,
        string $className = ''
    ): array {
        if ($depth > 4) {
            return array_map($this->deepClone(...), $method->stmts ?? []);
        }

        $result = [];
        foreach ($method->stmts ?? [] as $stmt) {
            $traverser = new NodeTraverser();
            $traverser->addVisitor($this->makeSubstitutor($method->params, $callArgs, $propVars, $className));
            [$cloned] = $traverser->traverse([$this->deepClone($stmt)]);
            $result[] = $cloned;
        }

        // Inline $this->method(args) void-call statements
        if ($classMethods !== []) {
            $expanded = [];
            foreach ($result as $stmt) {
                if (
                    $stmt instanceof Node\Stmt\Expression
                    && $stmt->expr instanceof Node\Expr\MethodCall
                    && $stmt->expr->var instanceof Node\Expr\Variable
                    && $stmt->expr->var->name === 'this'
                    && $stmt->expr->name instanceof Node\Identifier
                ) {
                    $mName = $stmt->expr->name->name;
                    if (isset($classMethods[$mName])) {
                        $inlined = $this->inlineMethodStmts(
                            $classMethods[$mName],
                            $stmt->expr->args,
                            $propVars,
                            $classMethods,
                            $depth + 1,
                            $className
                        );
                        array_push($expanded, ...$inlined);
                        continue;
                    }
                }

                $expanded[] = $stmt;
            }

            $result = $expanded;
        }

        return $result;
    }

    /**
     * Recursively clone a node and all its child nodes.
     */
    #[\Override]
    protected function deepClone(Node $node): Node
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
     * Build a visitor that substitutes parameter variables, $this->prop, and self/static class names.
     *
     * @param array<Node\Param>     $params
     * @param array<Node\Arg>       $callArgs
     * @param array<string, string> $propVars  propName → generatedVarName
     */
    private function makeSubstitutor(array $params, array $callArgs, array $propVars, string $className = ''): NodeVisitorAbstract
    {
        // Build named-arg lookup for fast resolution
        $argByName = [];
        foreach ($callArgs as $arg) {
            if ($arg instanceof Node\Arg && $arg->name instanceof Node\Identifier) {
                $argByName[$arg->name->toString()] = $arg->value;
            }
        }

        $paramMap = [];
        foreach ($params as $i => $param) {
            if (!($param->var instanceof Node\Expr\Variable)) {
                continue;
            }

            if (!is_string($param->var->name)) {
                continue;
            }

            $paramName = $param->var->name;

            // Named arg > positional arg > default
            if (isset($argByName[$paramName])) {
                $paramMap[$paramName] = clone $argByName[$paramName];
            } elseif (isset($callArgs[$i]) && $callArgs[$i] instanceof Node\Arg) {
                $paramMap[$paramName] = clone $callArgs[$i]->value;
            } elseif ($param->default !== null) {
                $paramMap[$paramName] = clone $param->default;
            } else {
                $paramMap[$paramName] = new Node\Expr\ConstFetch(new Node\Name('null'));
            }
        }

        return new class ($paramMap, $propVars, $className) extends NodeVisitorAbstract {
            /**
             * @param array<string, Node\Expr> $paramMap
             * @param array<string, string>    $propVars
             */
            public function __construct(
                private readonly array $paramMap,
                private readonly array $propVars,
                private readonly string $className,
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

                // Replace self/static → concrete class name in new/static-call/property/const contexts
                if ($this->className !== '') {
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
                }

                return null;
            }
        };
    }
}
