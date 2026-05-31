<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;
use PhpParser\PrettyPrinter\Standard as Printer;
use Recombinator\Core\ExecutionCache;
use Recombinator\Support\Sandbox;
use Recombinator\Support\SandboxResult;

/**
 * Отслеживает состояние свойств-массивов объектов через вызовы методов-сеттеров
 * и разворачивает цепочки вызовов методов-геттеров.
 *
 * Паттерн сеттера:   метод с телом `$this->prop[$keyParam] = $valueParam`
 * Паттерн геттера:   метод с телом `return $this->prop[$keyParam]` / `?? null`
 *
 * Пример:
 *   $shelter->updateStatus('Whiskers', Status::Adopted);
 *   $shelter->statusOf('Whiskers')?->isTerminal()  →  true
 *
 * Использует статический кэш результатов enum-методов, чтобы пережить
 * удаление enum-класса в EnumCaseInlinerVisitor.
 */
#[VisitorMeta('Разворачивание $obj->getter(key)?->method() через отслеживание сеттеров')]
class IndexedPropGetterResolverVisitor extends BaseVisitor
{
    /**
     * Статический кэш результатов enum-методов: пережива́ет смену прохода.
     * enumClass → methodName → caseName → phpValue
     * enumClass → methodName → '__by_value__' → backingValue → phpValue
     *
     * @var array<string, array<string, array<string, mixed>>>
     */
    private static array $enumMethodCache = [];

    /** @var array<string, array{prop: string, keyIdx: int, keyName: string}> className → methodName → info */
    private array $getterInfo = [];

    /** @var array<string, array{prop: string, keyIdx: int, keyName: string, valIdx: int, valName: string}> */
    private array $setterInfo = [];

    /**
     * Methods with isset-guard: if (!isset($this->prop[$key])) { return false; }
     * then $this->prop[$key] = $val; return true;
     *
     * @var array<string, array<string, array{prop: string, keyIdx: int, keyName: string, valIdx: int, valName: string}>>
     */
    private array $guardedSetterInfo = [];

    /** @var array<string, string> varName → className */
    private array $varTypes = [];

    /** @var array<string, array<string, array<string, Node\Expr>>> varName → prop → stringKey → valueNode */
    private array $propArrayState = [];

    private readonly Sandbox $sandbox;

    public function __construct()
    {
        $cache         = new ExecutionCache();
        $this->sandbox = new Sandbox($cache);
    }

    #[\Override]
    public function beforeTraverse(array $nodes): ?array
    {
        parent::beforeTraverse($nodes);
        $this->getterInfo       = [];
        $this->setterInfo       = [];
        $this->guardedSetterInfo = [];
        $this->varTypes         = [];
        $this->propArrayState   = [];

        $this->cacheEnumMethods();   // while enum may still be in AST
        $this->analyzeClasses();
        $this->trackTopLevelState($nodes);
        $this->markDeadSetterCalls($nodes);

        return null;
    }

    /**
     * After all getter calls are resolved (no longer present in AST), mark the
     * corresponding setter call statements as removable. This runs on the NEXT
     * pass after getters were inlined, so the AST no longer has them.
     */
    private function markDeadSetterCalls(array $nodes): void
    {
        foreach ($this->varTypes as $varName => $cls) {
            if (!isset($this->getterInfo[$cls])) {
                continue;
            }

            $hasGetterCalls = array_any($this->findNode(Node\Expr\MethodCall::class, $nodes), fn($call): bool => $call->var instanceof Node\Expr\Variable
            && $call->var->name === $varName
            && $call->name instanceof Node\Identifier
            && isset($this->getterInfo[$cls][$call->name->toString()]));

            if ($hasGetterCalls) {
                continue;
            }

            // No getter calls remain → mark standalone setter calls as dead
            $setterMethods = array_merge(
                array_keys($this->setterInfo[$cls] ?? []),
                array_keys($this->guardedSetterInfo[$cls] ?? [])
            );

            if ($setterMethods === []) {
                continue;
            }

            foreach ($this->findNode(Node\Stmt\Expression::class, $nodes) as $stmt) {
                if (
                    $stmt->expr instanceof Node\Expr\MethodCall
                    && $stmt->expr->var instanceof Node\Expr\Variable
                    && $stmt->expr->var->name === $varName
                    && $stmt->expr->name instanceof Node\Identifier
                    && in_array($stmt->expr->name->toString(), $setterMethods, true)
                ) {
                    $stmt->setAttribute('remove', true);
                }
            }
        }
    }

    // ── Enum method caching (survives pass boundaries via static) ─────────────

    private function cacheEnumMethods(): void
    {
        $printer = new Printer();

        foreach ($this->findNode(Node\Stmt\Enum_::class) as $enum) {
            if (!$enum->name instanceof Node\Identifier) {
                continue;
            }

            $enumName = $enum->name->toString();

            // Collect cases
            $cases = [];
            foreach ($enum->stmts as $stmt) {
                if ($stmt instanceof Node\Stmt\EnumCase && $stmt->name instanceof Node\Identifier) {
                    $cases[$stmt->name->toString()] = $stmt->expr;
                }
            }

            if ($cases === []) {
                continue;
            }

            $preamble = $printer->prettyPrint([$enum]);

            // For each no-param instance method
            foreach ($enum->stmts as $stmt) {
                if (!$stmt instanceof Node\Stmt\ClassMethod) {
                    continue;
                }

                if ($stmt->params !== []) {
                    continue;
                }

                $methodName = $stmt->name->toString();

                foreach ($cases as $caseName => $backingExpr) {
                    if (isset(self::$enumMethodCache[$enumName][$methodName][$caseName])) {
                        continue;
                    }

                    $callNode = new Node\Expr\MethodCall(
                        new Node\Expr\ClassConstFetch(
                            new Node\Name($enumName),
                            new Node\Identifier($caseName),
                        ),
                        new Node\Identifier($methodName),
                    );

                    $result = $this->sandbox->execute($callNode, [], $preamble);
                    if (!$result instanceof SandboxResult) {
                        continue;
                    }

                    self::$enumMethodCache[$enumName][$methodName][$caseName] = $result->value;

                    if ($backingExpr instanceof Node\Scalar\String_) {
                        self::$enumMethodCache[$enumName][$methodName]['__by_value__'][$backingExpr->value] = $result->value;
                    } elseif ($backingExpr instanceof Node\Scalar\LNumber) {
                        self::$enumMethodCache[$enumName][$methodName]['__by_value__'][$backingExpr->value] = $result->value;
                    }
                }
            }
        }
    }

    // ── Class analysis: detect getter / setter methods ────────────────────────

    private function analyzeClasses(): void
    {
        foreach ($this->findNode(Node\Stmt\Class_::class) as $class) {
            if (!$class->name instanceof Node\Identifier) {
                continue;
            }

            $className = $class->name->toString();
            foreach ($class->getMethods() as $method) {
                $this->tryRegisterGetter($className, $method);
                $this->tryRegisterSetter($className, $method);
                $this->tryRegisterGuardedSetter($className, $method);
            }
        }
    }

    private function tryRegisterGetter(string $className, Node\Stmt\ClassMethod $method): void
    {
        // Single return statement: return $this->prop[$keyParam] [?? expr]
        if ($method->stmts === null || count($method->stmts) !== 1) {
            return;
        }

        if (!$method->stmts[0] instanceof Node\Stmt\Return_) {
            return;
        }

        $returnExpr = $method->stmts[0]->expr;

        $dimFetch = null;
        if ($returnExpr instanceof Node\Expr\ArrayDimFetch) {
            $dimFetch = $returnExpr;
        } elseif (
            $returnExpr instanceof Node\Expr\BinaryOp\Coalesce
            && $returnExpr->left instanceof Node\Expr\ArrayDimFetch
        ) {
            $dimFetch = $returnExpr->left;
        }

        if (
            $dimFetch instanceof \PhpParser\Node\Expr\ArrayDimFetch
            && $dimFetch->var instanceof Node\Expr\PropertyFetch
            && $dimFetch->var->var instanceof Node\Expr\Variable
            && $dimFetch->var->var->name === 'this'
            && $dimFetch->var->name instanceof Node\Identifier
            && $dimFetch->dim instanceof Node\Expr\Variable
            && is_string($dimFetch->dim->name)
        ) {
            $propName    = $dimFetch->var->name->toString();
            $keyParamName = $dimFetch->dim->name;

            foreach ($method->params as $i => $param) {
                if (
                    $param->var instanceof Node\Expr\Variable
                    && $param->var->name === $keyParamName
                ) {
                    $this->getterInfo[$className][$method->name->toString()] = [
                        'prop'    => $propName,
                        'keyIdx'  => $i,
                        'keyName' => $keyParamName,
                    ];
                    return;
                }
            }
        }
    }

    private function tryRegisterSetter(string $className, Node\Stmt\ClassMethod $method): void
    {
        // Look for: $this->prop[$keyParam] = $valueParam (any statement)
        foreach ($method->stmts ?? [] as $stmt) {
            if (!$stmt instanceof Node\Stmt\Expression) {
                continue;
            }

            $expr = $stmt->expr;
            if (!$expr instanceof Node\Expr\Assign) {
                continue;
            }

            if (!$expr->var instanceof Node\Expr\ArrayDimFetch) {
                continue;
            }

            if (!$expr->var->var instanceof Node\Expr\PropertyFetch) {
                continue;
            }

            if (!$expr->var->var->var instanceof Node\Expr\Variable) {
                continue;
            }

            if ($expr->var->var->var->name !== 'this') {
                continue;
            }

            if (!$expr->var->var->name instanceof Node\Identifier) {
                continue;
            }

            if (!$expr->var->dim instanceof Node\Expr\Variable) {
                continue;
            }

            if (!is_string($expr->var->dim->name)) {
                continue;
            }

            if (!$expr->expr instanceof Node\Expr\Variable) {
                continue;
            }

            if (!is_string($expr->expr->name)) {
                continue;
            }

            $propName     = $expr->var->var->name->toString();
            $keyParamName = $expr->var->dim->name;
            $valParamName = $expr->expr->name;

            $keyIdx = null;
            $valIdx = null;
            foreach ($method->params as $i => $param) {
                if (!$param->var instanceof Node\Expr\Variable) {
                    continue;
                }

                if ($param->var->name === $keyParamName) {
                    $keyIdx = $i;
                }

                if ($param->var->name === $valParamName) {
                    $valIdx = $i;
                }
            }

            if ($keyIdx !== null && $valIdx !== null) {
                $this->setterInfo[$className][$method->name->toString()] = [
                    'prop'    => $propName,
                    'keyIdx'  => $keyIdx,
                    'keyName' => $keyParamName,
                    'valIdx'  => $valIdx,
                    'valName' => $valParamName,
                ];
                return;
            }
        }
    }

    /**
     * Detects isset-guarded setters:
     *   if (!isset($this->prop[$keyParam])) { return ...; }
     *   $this->prop[$keyParam] = $valueParam;
     *   return ...;
     */
    private function tryRegisterGuardedSetter(string $className, Node\Stmt\ClassMethod $method): void
    {
        $stmts = $method->stmts ?? [];
        if (count($stmts) < 2) {
            return;
        }

        // First stmt must be: if (!isset($this->prop[$keyParam])) { return ...; }
        $first = $stmts[0];
        if (
            !$first instanceof Node\Stmt\If_
            || !$first->cond instanceof Node\Expr\BooleanNot
            || !$first->cond->expr instanceof Node\Expr\Isset_
            || count($first->cond->expr->vars) !== 1
            || $first->elseifs !== []
            || $first->else instanceof \PhpParser\Node\Stmt\Else_
            || count($first->stmts) !== 1
            || !$first->stmts[0] instanceof Node\Stmt\Return_
        ) {
            return;
        }

        $issetVar = $first->cond->expr->vars[0];
        if (
            !$issetVar instanceof Node\Expr\ArrayDimFetch
            || !$issetVar->var instanceof Node\Expr\PropertyFetch
            || !$issetVar->var->var instanceof Node\Expr\Variable
            || $issetVar->var->var->name !== 'this'
            || !$issetVar->var->name instanceof Node\Identifier
            || !$issetVar->dim instanceof Node\Expr\Variable
            || !is_string($issetVar->dim->name)
        ) {
            return;
        }

        $propName     = $issetVar->var->name->toString();
        $keyParamName = $issetVar->dim->name;

        // Find the assignment $this->prop[$keyParam] = $valueParam among remaining stmts
        $valParamName = null;
        foreach (array_slice($stmts, 1) as $stmt) {
            if (!$stmt instanceof Node\Stmt\Expression) {
                continue;
            }

            $expr = $stmt->expr;
            if (
                $expr instanceof Node\Expr\Assign
                && $expr->var instanceof Node\Expr\ArrayDimFetch
                && $expr->var->var instanceof Node\Expr\PropertyFetch
                && $expr->var->var->var instanceof Node\Expr\Variable
                && $expr->var->var->var->name === 'this'
                && $expr->var->var->name instanceof Node\Identifier
                && $expr->var->var->name->toString() === $propName
                && $expr->var->dim instanceof Node\Expr\Variable
                && is_string($expr->var->dim->name)
                && $expr->var->dim->name === $keyParamName
                && $expr->expr instanceof Node\Expr\Variable
                && is_string($expr->expr->name)
            ) {
                $valParamName = $expr->expr->name;
                break;
            }
        }

        if ($valParamName === null) {
            return;
        }

        $keyIdx = null;
        $valIdx = null;
        foreach ($method->params as $i => $param) {
            if (!$param->var instanceof Node\Expr\Variable) {
                continue;
            }

            if ($param->var->name === $keyParamName) {
                $keyIdx = $i;
            }

            if ($param->var->name === $valParamName) {
                $valIdx = $i;
            }
        }

        if ($keyIdx === null) {
            return;
        }

        $this->guardedSetterInfo[$className][$method->name->toString()] = [
            'prop'    => $propName,
            'keyIdx'  => $keyIdx,
            'keyName' => $keyParamName,
            'valIdx'  => $valIdx ?? -1,
            'valName' => $valParamName,
        ];
    }

    // ── Top-level state tracking ──────────────────────────────────────────────

    private function trackTopLevelState(array $nodes): void
    {
        foreach ($nodes as $stmt) {
            if (!$stmt instanceof Node\Stmt\Expression) {
                continue;
            }

            $expr = $stmt->expr;

            // $var = new ClassName(...)
            if (
                $expr instanceof Node\Expr\Assign
                && $expr->var instanceof Node\Expr\Variable
                && is_string($expr->var->name)
                && $expr->expr instanceof Node\Expr\New_
                && $expr->expr->class instanceof Node\Name
            ) {
                $this->varTypes[$expr->var->name] = $expr->expr->class->toString();
                continue;
            }

            // $obj->setter(key, value)
            if (
                $expr instanceof Node\Expr\MethodCall
                && $expr->var instanceof Node\Expr\Variable
                && is_string($expr->var->name)
                && $expr->name instanceof Node\Identifier
            ) {
                $varName    = $expr->var->name;
                $methodName = $expr->name->toString();
                $className  = $this->varTypes[$varName] ?? null;
                if ($className === null) {
                    continue;
                }

                if (!isset($this->setterInfo[$className][$methodName])) {
                    continue;
                }

                $setter = $this->setterInfo[$className][$methodName];
                $keyArg = $this->getArg($expr->args, $setter['keyIdx'], $setter['keyName']);
                $valArg = $this->getArg($expr->args, $setter['valIdx'], $setter['valName']);

                if ($keyArg instanceof Node\Scalar\String_ && $valArg instanceof \PhpParser\Node\Expr) {
                    $this->propArrayState[$varName][$setter['prop']][$keyArg->value] = $valArg;
                }
            }
        }
    }

    // ── Resolution ────────────────────────────────────────────────────────────

    #[\Override]
    public function leaveNode(Node $node): int|Node|array|null
    {
        // !$obj->guardedSetter(key, val) → true (key absent) / false (key present)
        // Only resolves in expression context (not standalone stmt) — matches BooleanNot wrapper.
        if (
            $node instanceof Node\Expr\BooleanNot
            && $node->expr instanceof Node\Expr\MethodCall
            && $node->expr->var instanceof Node\Expr\Variable
            && is_string($node->expr->var->name)
            && $node->expr->name instanceof Node\Identifier
        ) {
            $varName    = $node->expr->var->name;
            $methodName = $node->expr->name->toString();
            $className  = $this->varTypes[$varName] ?? null;

            if ($className !== null && isset($this->guardedSetterInfo[$className][$methodName])) {
                $gs     = $this->guardedSetterInfo[$className][$methodName];
                $keyArg = $this->getArg($node->expr->args, $gs['keyIdx'], $gs['keyName']);

                if ($keyArg instanceof Node\Scalar\String_) {
                    $exists = isset($this->propArrayState[$varName][$gs['prop']][$keyArg->value]);
                    return new Node\Expr\ConstFetch(new Node\Name($exists ? 'false' : 'true'));
                }
            }
        }

        // $obj->getter(key)?->method(args)
        if (
            $node instanceof Node\Expr\NullsafeMethodCall
            && $node->var instanceof Node\Expr\MethodCall
            && $node->var->var instanceof Node\Expr\Variable
            && is_string($node->var->var->name)
            && $node->var->name instanceof Node\Identifier
            && $node->name instanceof Node\Identifier
        ) {
            $varName      = $node->var->var->name;
            $getterName   = $node->var->name->toString();
            $chainedMethod = $node->name->toString();
            $className    = $this->varTypes[$varName] ?? null;

            if ($className !== null && isset($this->getterInfo[$className][$getterName])) {
                $getter = $this->getterInfo[$className][$getterName];
                $keyArg = $this->getArg($node->var->args, $getter['keyIdx'], $getter['keyName']);

                if ($keyArg instanceof Node\Scalar\String_) {
                    $trackedValue = $this->propArrayState[$varName][$getter['prop']][$keyArg->value] ?? null;

                    if ($trackedValue !== null) {
                        $result = $this->resolveMethodOnValue($trackedValue, $chainedMethod);
                        if ($result instanceof \PhpParser\Node\Expr) {
                            return $result;
                        }
                    }
                }
            }
        }

        return parent::leaveNode($node);
    }

    private function resolveMethodOnValue(Node\Expr $valueExpr, string $methodName): ?Node\Expr
    {
        // 1. ClassConstFetch (e.g. Status::Adopted) — check static cache first
        if (
            $valueExpr instanceof Node\Expr\ClassConstFetch
            && $valueExpr->class instanceof Node\Name
            && $valueExpr->name instanceof Node\Identifier
        ) {
            $enumName = $valueExpr->class->toString();
            $caseName = $valueExpr->name->toString();

            if (isset(self::$enumMethodCache[$enumName][$methodName][$caseName])) {
                return $this->scalarToNode(self::$enumMethodCache[$enumName][$methodName][$caseName]);
            }

            // Fallback: sandbox (enum still in AST in this pass)
            $printer = new Printer();
            foreach ($this->findNode(Node\Stmt\Enum_::class) as $enum) {
                if (
                    $enum->name instanceof Node\Identifier
                    && $enum->name->toString() === $enumName
                ) {
                    $preamble = $printer->prettyPrint([$enum]);
                    $callNode = new Node\Expr\MethodCall(
                        $this->deepClone($valueExpr),
                        new Node\Identifier($methodName),
                    );
                    $result = $this->sandbox->execute($callNode, [], $preamble);
                    if ($result instanceof SandboxResult) {
                        self::$enumMethodCache[$enumName][$methodName][$caseName] = $result->value;
                        return $this->scalarToNode($result->value);
                    }

                    break;
                }
            }
        }

        // 2. String (backed-enum value, e.g. 'adopted') — look up __by_value__ cache
        if ($valueExpr instanceof Node\Scalar\String_) {
            $backingVal = $valueExpr->value;
            foreach (self::$enumMethodCache as $methods) {
                if (isset($methods[$methodName]['__by_value__'][$backingVal])) {
                    return $this->scalarToNode($methods[$methodName]['__by_value__'][$backingVal]);
                }
            }

            // Fallback: sandbox with Status::from('value')->method()
            $printer = new Printer();
            foreach ($this->findNode(Node\Stmt\Enum_::class) as $enum) {
                if ($enum->scalarType === null) {
                    continue;
                }

                foreach ($enum->stmts as $caseStmt) {
                    if (
                        $caseStmt instanceof Node\Stmt\EnumCase
                        && $caseStmt->expr instanceof Node\Scalar\String_
                        && $caseStmt->expr->value === $backingVal
                    ) {
                        $enumName = $enum->name->toString();
                        $preamble = $printer->prettyPrint([$enum]);
                        $fromCall = new Node\Expr\StaticCall(
                            new Node\Name($enumName),
                            new Node\Identifier('from'),
                            [new Node\Arg($this->deepClone($valueExpr))],
                        );
                        $callNode = new Node\Expr\MethodCall(
                            $fromCall,
                            new Node\Identifier($methodName),
                        );
                        $result = $this->sandbox->execute($callNode, [], $preamble);
                        if ($result instanceof SandboxResult) {
                            self::$enumMethodCache[$enumName][$methodName]['__by_value__'][$backingVal] = $result->value;
                            return $this->scalarToNode($result->value);
                        }

                        break 2;
                    }
                }
            }
        }

        return null;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * @param array<Node\Arg|Node\VariadicPlaceholder> $args
     */
    private function getArg(array $args, int $paramIdx, string $paramName): ?Node\Expr
    {
        foreach ($args as $arg) {
            if (
                $arg instanceof Node\Arg
                && $arg->name instanceof Node\Identifier
                && $arg->name->toString() === $paramName
            ) {
                return $arg->value;
            }
        }

        $arg = $args[$paramIdx] ?? null;
        return $arg instanceof Node\Arg && !$arg->name instanceof \PhpParser\Node\Identifier ? $arg->value : null;
    }

    private function scalarToNode(mixed $value): ?Node\Expr
    {
        if ($value === null) {
            return new Node\Expr\ConstFetch(new Node\Name('null'));
        }

        if (is_bool($value)) {
            return new Node\Expr\ConstFetch(new Node\Name($value ? 'true' : 'false'));
        }

        if (is_int($value)) {
            return new Node\Scalar\LNumber($value);
        }

        if (is_float($value)) {
            return new Node\Scalar\DNumber($value);
        }

        if (is_string($value)) {
            return new Node\Scalar\String_($value);
        }

        return null;
    }
}
