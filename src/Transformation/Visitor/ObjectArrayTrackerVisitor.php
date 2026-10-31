<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;

/**
 * Отслеживает мутируемые массивы объектов — паттерн "коллекция с capacity".
 *
 * Сценарий использования:
 *   $shelter = new Shelter(city: 'Berlin', capacity: 10);
 *   $shelter->admit($dog1, $dog2, $cat);       // variadic append
 *   foreach ($shelter->bySpecies('dog') as …)  // generator → literal array
 *   foreach ($shelter->all() as …)             // generator → literal array
 *
 * Выполняет три трансформации:
 * 1. `$n = $obj->admit(args)` → `$n = count(filteredArgs)` (scalar)
 * 2. `$obj->bySpecies('x')` в foreach-expr → `[literal_array]`
 * 3. `$obj->all()` в foreach-expr           → `[literal_array]`
 *
 * После этих трансформаций ForeachLiteralUnrollerVisitor раскроет петли,
 * а RemoveUnusedPureVisitor / VarToScalarVisitor устранят мёртвый код.
 */
#[VisitorMeta('Отслеживание коллекций объектов и разворачивание generator-методов', targets: [Node\Stmt\Class_::class])]
class ObjectArrayTrackerVisitor extends BaseVisitor
{
    /** @var array<string, string> varName → className */
    private array $varTypes = [];

    /**
     * Tracked object arrays: varName → list of [expr, className, speciesValue]
     *
     * @var array<string, list<array{expr: Node\Expr, class: string, species: string|null}>>
     */
    private array $objectArrays = [];

    /**
     * Capacity per tracked var: varName → int
     *
     * @var array<string, int>
     */
    private array $capacities = [];

    /**
     * Admit method name per class: className → methodName
     * (methods that append variadic arg to a private array and return count)
     *
     * @var array<string, string>
     */
    private array $admitMethods = [];

    /**
     * Generator methods: className → methodName → ['filter_prop' => ..., 'filter_param_idx' => ...]
     * 'all' methods have null filter.
     *
     * @var array<string, array<string, array{type: 'all'|'filtered', filterParamIdx: int|null}>>
     */
    private array $generatorMethods = [];

    /**
     * Species lookup: className → species string (from `species()` single-return method)
     *
     * @var array<string, string|null>
     */
    private array $speciesMap = [];

    /**
     * Array prop name per class (the one used in the admit-like method)
     *
     * @var array<string, string>
     */
    private array $arrayPropNames = [];

    /**
     * Replacement for $obj->admit(...) expressions: spl_object_id → Node\Expr
     *
     * @var array<int, Node\Expr>
     */
    private array $admitReplacements = [];

    /**
     * Replacement for $obj->bySpecies() / $obj->all() in foreach-expr
     *
     * @var array<int, Node\Expr\Array_>
     */
    private array $foreachExprReplacements = [];

    /**
     * Vars where at least one generator method was resolved → standalone method calls are dead
     *
     * @var array<string, true>
     */
    private array $resolvedVars = [];

    /**
     * Объекты, состав коллекции которых статически не определить
     *
     * @var array<string, true>
     */
    private array $untrackedVars = [];

    #[\Override]
    public function beforeTraverse(array $nodes): ?array
    {
        parent::beforeTraverse($nodes);

        $this->varTypes              = [];
        $this->objectArrays          = [];
        $this->capacities            = [];
        $this->admitMethods          = [];
        $this->generatorMethods      = [];
        $this->speciesMap            = [];
        $this->arrayPropNames        = [];
        $this->admitReplacements     = [];
        $this->foreachExprReplacements = [];
        $this->resolvedVars          = [];
        $this->untrackedVars         = [];

        $this->analyzeClasses();
        $this->collectVarTypes($nodes);
        $this->collectAdmitCalls($nodes);
        $this->buildForeachReplacements($nodes);

        return null;
    }

    // ── Class analysis ────────────────────────────────────────────────────────

    private function analyzeClasses(): void
    {
        foreach ($this->findNode(Node\Stmt\Class_::class) as $class) {
            if (!$class->name instanceof Node\Identifier) {
                continue;
            }

            $className = $class->name->toString();

            $this->speciesMap[$className] = $this->detectSpeciesMethod($class);
            $this->detectAdmitMethod($class, $className);
            $this->detectGeneratorMethods($class, $className);
        }
    }

    /** Returns the literal species string from `species() { return 'literal'; }`, or null. */
    private function detectSpeciesMethod(Node\Stmt\Class_ $class): ?string
    {
        foreach ($class->getMethods() as $method) {
            if ($method->name->toString() !== 'species') {
                continue;
            }

            $stmts = $method->stmts ?? [];
            if (count($stmts) === 1 && $stmts[0] instanceof Node\Stmt\Return_) {
                $expr = $stmts[0]->expr;
                if ($expr instanceof Node\Scalar\String_) {
                    return $expr->value;
                }
            }
        }

        return null;
    }

    /**
     * Detects variadic methods that do `$this->prop[] = $arg; ++$counter; return $counter`
     * or similar append+count patterns.
     */
    /**
     * Метод только добавляет в $this->$prop[] и пишет в свои локальные переменные;
     * вызывает лишь чистые функции.
     *
     * @param array<Node\Stmt> $stmts
     */
    private function onlyAppends(array $stmts, string $prop): bool
    {
        foreach ($this->findNode(Node::class, $stmts) as $node) {
            if (
                $node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\NullsafeMethodCall
                || $node instanceof Node\Expr\StaticCall || $node instanceof Node\Expr\New_
                || $node instanceof Node\Expr\Include_ || $node instanceof Node\Stmt\Echo_ || $node instanceof Node\Expr\Print_
            ) {
                return false;
            }

            if (
                $node instanceof Node\Expr\FuncCall
                && (!$node->name instanceof Node\Name || !RemoveUnusedPureVisitor::isKnownPureFunction(strtolower($node->name->toString())))
            ) {
                return false;
            }

            $target = match (true) {
                $node instanceof Node\Expr\Assign, $node instanceof Node\Expr\AssignOp, $node instanceof Node\Expr\AssignRef,
                $node instanceof Node\Expr\PreInc, $node instanceof Node\Expr\PreDec,
                $node instanceof Node\Expr\PostInc, $node instanceof Node\Expr\PostDec => $node->var,
                default => null,
            };
            if (!$target instanceof \PhpParser\Node\Expr || $target instanceof Node\Expr\Variable && $target->name !== 'this') {
                continue;
            }

            $isAppend = $target instanceof Node\Expr\ArrayDimFetch
                && !$target->dim instanceof Node\Expr
                && $target->var instanceof Node\Expr\PropertyFetch
                && $target->var->var instanceof Node\Expr\Variable
                && $target->var->var->name === 'this'
                && $target->var->name instanceof Node\Identifier
                && $target->var->name->toString() === $prop;
            if (!$isAppend) {
                return false;
            }
        }

        return true;
    }

    private function detectAdmitMethod(Node\Stmt\Class_ $class, string $className): void
    {
        foreach ($class->getMethods() as $method) {
            // Must have at least one variadic param
            $variadicParam = null;
            foreach ($method->params as $param) {
                if ($param->variadic && $param->var instanceof Node\Expr\Variable && is_string($param->var->name)) {
                    $variadicParam = $param->var->name;
                    break;
                }
            }

            if ($variadicParam === null) {
                continue;
            }

            $stmts = $method->stmts ?? [];
            if (count($stmts) < 2) {
                continue;
            }

            // Must contain $this->prop[] = $something (array-append)
            $appendProp = null;
            foreach ($this->findNode(Node\Expr\Assign::class, $stmts) as $assign) {
                $lhs = $assign->var;
                if (
                    $lhs instanceof Node\Expr\ArrayDimFetch
                    && !$lhs->dim instanceof \PhpParser\Node\Expr  // [] append syntax
                    && $lhs->var instanceof Node\Expr\PropertyFetch
                    && $lhs->var->var instanceof Node\Expr\Variable
                    && $lhs->var->var->name === 'this'
                    && $lhs->var->name instanceof Node\Identifier
                ) {
                    $appendProp = $lhs->var->name->toString();
                    break;
                }
            }

            if ($appendProp === null) {
                continue;
            }

            // Must end with `return $intExpr`
            $last = end($stmts);
            if (!$last instanceof Node\Stmt\Return_) {
                continue;
            }

            // Вызов заменяется числом, поэтому других эффектов быть не должно:
            // `$this->statuses[$a->name] = ...` в admit() пропал бы вместе с вызовом
            if (!$this->onlyAppends($stmts, $appendProp)) {
                continue;
            }

            $this->admitMethods[$className]   = $method->name->toString();
            $this->arrayPropNames[$className] = $appendProp;
        }
    }

    private function detectGeneratorMethods(Node\Stmt\Class_ $class, string $className): void
    {
        foreach ($class->getMethods() as $method) {
            $stmts = $method->stmts ?? [];

            // Detect `yield from $this->prop` (all-items generator)
            foreach ($this->findNode(Node\Expr\YieldFrom::class, $stmts) as $yf) {
                if (
                    $yf->expr instanceof Node\Expr\PropertyFetch
                    && $yf->expr->var instanceof Node\Expr\Variable
                    && $yf->expr->var->name === 'this'
                ) {
                    $this->generatorMethods[$className][$method->name->toString()] = [
                        'type'           => 'all',
                        'filterParamIdx' => null,
                    ];
                }
            }

            // Detect `foreach ($this->prop as ...) { if ($x->method() === $param) yield $k => $x; }`
            foreach ($this->findNode(Node\Stmt\Foreach_::class, $stmts) as $fe) {
                if (!$fe->expr instanceof Node\Expr\PropertyFetch) {
                    continue;
                }

                if (!$fe->expr->var instanceof Node\Expr\Variable) {
                    continue;
                }

                if ($fe->expr->var->name !== 'this') {
                    continue;
                }

                // Look for a yield inside this foreach
                if ($this->findNode(Node\Expr\Yield_::class, $fe->stmts) === []) {
                    continue;
                }

                // Look for an if-condition comparing a method call with a string param
                foreach ($this->findNode(Node\Stmt\If_::class, $fe->stmts) as $if) {
                    $cond = $if->cond;
                    if (
                        $cond instanceof Node\Expr\BinaryOp\Identical
                        || $cond instanceof Node\Expr\BinaryOp\Equal
                    ) {
                        // Either left or right should be a param variable
                        $methodCallSide = null;
                        $paramSide      = null;

                        foreach ([$cond->left, $cond->right] as $side) {
                            if ($side instanceof Node\Expr\MethodCall) {
                                $methodCallSide = $side;
                            } elseif ($side instanceof Node\Expr\Variable && is_string($side->name)) {
                                $paramSide = $side->name;
                            }
                        }

                        if (!$methodCallSide instanceof \PhpParser\Node\Expr\MethodCall) {
                            continue;
                        }

                        if ($paramSide === null) {
                            continue;
                        }

                        // Find which param index $paramSide is in the method
                        $filterParamIdx = null;
                        foreach ($method->params as $i => $param) {
                            if (
                                $param->var instanceof Node\Expr\Variable
                                && is_string($param->var->name)
                                && $param->var->name === $paramSide
                            ) {
                                $filterParamIdx = $i;
                                break;
                            }
                        }

                        if ($filterParamIdx !== null) {
                            $this->generatorMethods[$className][$method->name->toString()] = [
                                'type'           => 'filtered',
                                'filterParamIdx' => $filterParamIdx,
                            ];
                        }
                    }
                }
            }
        }
    }

    // ── Variable type collection ──────────────────────────────────────────────

    private function collectVarTypes(array $nodes): void
    {
        foreach ($this->findNode(Node\Expr\Assign::class, $nodes) as $assign) {
            if (!$assign->var instanceof Node\Expr\Variable) {
                continue;
            }

            if (!is_string($assign->var->name)) {
                continue;
            }

            $varName = $assign->var->name;

            if (
                $assign->expr instanceof Node\Expr\New_
                && $assign->expr->class instanceof Node\Name
            ) {
                $this->varTypes[$varName] = $assign->expr->class->toString();

                // If this class has an admit method, initialize its object array
                $cls = $assign->expr->class->toString();
                if (isset($this->admitMethods[$cls]) && !isset($this->objectArrays[$varName])) {
                    $this->objectArrays[$varName] = [];
                    // Detect capacity from constructor args
                    foreach ($assign->expr->args as $i => $arg) {
                        if (
                            $arg instanceof Node\Arg
                            && (
                                ($arg->name instanceof Node\Identifier && $arg->name->toString() === 'capacity')
                                || $i === 1
                            )
                            && $arg->value instanceof Node\Scalar\LNumber
                        ) {
                            $this->capacities[$varName] = $arg->value->value;
                        }
                    }
                }
            }
        }
    }

    // ── Admit call tracking ───────────────────────────────────────────────────

    private function collectAdmitCalls(array $nodes): void
    {
        // Вызовы в порядке выполнения прямолинейного кода верхнего уровня
        foreach ($this->findNode(Node\Expr\MethodCall::class, $nodes) as $call) {
            if (!$this->isAdmitCall($call) || !$call->var instanceof Node\Expr\Variable || !is_string($call->var->name)) {
                continue;
            }

            $objVar = $call->var->name;

            // В цикле, ветвлении или функции число вызовов статически неизвестно
            if (!$this->isStraightLineTopLevel($call)) {
                $this->untrackedVars[$objVar] = true;
                continue;
            }

            $admitted = $this->processAdmitCall($objVar, $call->args);
            if ($admitted === null) {
                $this->untrackedVars[$objVar] = true;
                continue;
            }

            $parent = $call->getAttribute('parent');
            if (!$parent instanceof Node\Stmt\Expression) {
                // $n = $obj->admit(...) / echo "{$obj->admit(...)}" → количество
                $this->admitReplacements[spl_object_id($call)] = new Node\Scalar\Int_($admitted);
            }
        }
    }

    private function isStraightLineTopLevel(Node $node): bool
    {
        for ($p = $node->getAttribute('parent'); $p instanceof Node; $p = $p->getAttribute('parent')) {
            if (
                $p instanceof Node\FunctionLike || $p instanceof Node\Stmt\ClassLike
                || $p instanceof Node\Stmt\If_ || $p instanceof Node\Stmt\Switch_ || $p instanceof Node\Stmt\TryCatch
                || $p instanceof Node\Stmt\For_ || $p instanceof Node\Stmt\Foreach_ || $p instanceof Node\Stmt\While_ || $p instanceof Node\Stmt\Do_
                || $p instanceof Node\Expr\Ternary || $p instanceof Node\Expr\Match_
                || $p instanceof Node\Expr\BinaryOp\BooleanAnd || $p instanceof Node\Expr\BinaryOp\BooleanOr
                || $p instanceof Node\Expr\BinaryOp\Coalesce
            ) {
                return false;
            }
        }

        return true;
    }

    private function isAdmitCall(Node\Expr\MethodCall $call): bool
    {
        if (
            !$call->var instanceof Node\Expr\Variable
            || !is_string($call->var->name)
            || !$call->name instanceof Node\Identifier
        ) {
            return false;
        }

        $varName    = $call->var->name;
        $methodName = $call->name->toString();
        $cls        = $this->varTypes[$varName] ?? null;

        return $cls !== null && ($this->admitMethods[$cls] ?? null) === $methodName;
    }

    /**
     * Flattens spread/non-spread args, tracks them in $this->objectArrays[$objVar],
     * returns the admitted count (capped by capacity).
     */
    private function processAdmitCall(string $objVar, array $args): ?int
    {
        $flatExprs = $this->flattenArgs($args);
        if ($flatExprs === null) {
            return null;
        }

        $capacity = $this->capacities[$objVar] ?? PHP_INT_MAX;
        $current  = count($this->objectArrays[$objVar] ?? []);
        $admitted = 0;

        foreach ($flatExprs as $expr) {
            if ($current >= $capacity) {
                break;
            }

            $className = $this->getExprClass($expr);
            $species   = $className !== null ? ($this->speciesMap[$className] ?? null) : null;

            $this->objectArrays[$objVar][] = [
                'expr'    => $expr,
                'class'   => $className ?? '',
                'species' => $species,
            ];

            $current++;
            $admitted++;
        }

        return $admitted;
    }

    /**
     * Flattens variadic args — `...[a, b]` → [a, b], `$x` → [$x], etc.
     *
     * @param  array<Node\Arg|Node\VariadicPlaceholder> $args
     * @return list<Node\Expr>
     */
    private function flattenArgs(array $args): ?array
    {
        $result = [];

        foreach ($args as $arg) {
            if (!$arg instanceof Node\Arg) {
                return null;
            }

            if ($arg->unpack) {
                // ...[expr1, expr2] → expr1, expr2; распаковка переменной — состав неизвестен
                if (!$arg->value instanceof Node\Expr\Array_) {
                    return null;
                }

                foreach ($arg->value->items as $item) {
                    if (!$item instanceof Node\ArrayItem || $item->unpack) {
                        return null;
                    }

                    $result[] = $item->value;
                }
            } else {
                $result[] = $arg->value;
            }
        }

        return $result;
    }

    /** Returns the class name for an expression (New_→class name, Variable→varTypes lookup). */
    private function getExprClass(Node\Expr $expr): ?string
    {
        if ($expr instanceof Node\Expr\New_ && $expr->class instanceof Node\Name) {
            return $expr->class->toString();
        }

        if ($expr instanceof Node\Expr\Variable && is_string($expr->name)) {
            return $this->varTypes[$expr->name] ?? null;
        }

        return null;
    }

    // ── Foreach replacement building ──────────────────────────────────────────

    private function buildForeachReplacements(array $nodes): void
    {
        foreach ($this->findNode(Node\Stmt\Foreach_::class, $nodes) as $fe) {
            if (!$fe->expr instanceof Node\Expr\MethodCall) {
                continue;
            }

            $call = $fe->expr;
            if (!$call->var instanceof Node\Expr\Variable) {
                continue;
            }

            if (!is_string($call->var->name)) {
                continue;
            }

            if (!$call->name instanceof Node\Identifier) {
                continue;
            }

            $objVar     = $call->var->name;
            $methodName = $call->name->toString();
            $cls        = $this->varTypes[$objVar] ?? null;
            if ($cls === null || isset($this->untrackedVars[$objVar])) {
                continue;
            }

            if (!isset($this->generatorMethods[$cls][$methodName])) {
                continue;
            }

            // Содержимое известно, только если массив объекта отслеживается
            // (иначе пустой список подставился бы вместо реального)
            if (!isset($this->objectArrays[$objVar])) {
                continue;
            }

            $methodInfo = $this->generatorMethods[$cls][$methodName];
            $animals    = $this->objectArrays[$objVar];

            if ($methodInfo['type'] === 'all') {
                $items = [];
                foreach ($animals as $idx => $entry) {
                    $items[] = new Node\ArrayItem(
                        $this->deepClone($entry['expr']),
                        new Node\Scalar\LNumber($idx)
                    );
                }

                $this->foreachExprReplacements[spl_object_id($call)] = new Node\Expr\Array_($items);
                continue;
            }

            // filtered (bySpecies)
            $filterParamIdx = $methodInfo['filterParamIdx'];
            if ($filterParamIdx === null) {
                continue;
            }

            $filterArg = $call->args[$filterParamIdx] ?? null;
            if (!$filterArg instanceof Node\Arg) {
                continue;
            }

            if (!$filterArg->value instanceof Node\Scalar\String_) {
                continue;
            }

            $filterSpecies = $filterArg->value->value;
            $items         = [];
            foreach ($animals as $idx => $entry) {
                if ($entry['species'] === $filterSpecies) {
                    $items[] = new Node\ArrayItem(
                        $this->deepClone($entry['expr']),
                        new Node\Scalar\LNumber($idx)
                    );
                }
            }

            $this->foreachExprReplacements[spl_object_id($call)] = new Node\Expr\Array_($items);
            $this->resolvedVars[$objVar] = true;
        }
    }

    // ── Node transformation ───────────────────────────────────────────────────

    #[\Override]
    public function leaveNode(Node $node): int|Node|array|null
    {
        // $admitted = $shelter->admit(...) → $admitted = 3 (и в любой позиции выражения)
        if (
            $node instanceof Node\Expr\MethodCall
            && isset($this->admitReplacements[spl_object_id($node)])
        ) {
            return $this->admitReplacements[spl_object_id($node)];
        }

        // Replace foreach expr: $shelter->bySpecies('dog') → literal array
        if (
            $node instanceof Node\Expr\MethodCall
            && isset($this->foreachExprReplacements[spl_object_id($node)])
        ) {
            return $this->foreachExprReplacements[spl_object_id($node)];
        }

        return parent::leaveNode($node);
    }
}
