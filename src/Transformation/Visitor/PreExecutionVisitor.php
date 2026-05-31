<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;
use PhpParser\Node\Scalar;
use PhpParser\PrettyPrinter\Standard as Printer;
use Recombinator\Core\ExecutionCache;
use Recombinator\Support\Sandbox;
use Recombinator\Support\SandboxResult;
use Recombinator\Domain\ScopeStore;

/**
 * Visitor для предвыполнения "чистого" кода в compile-time
 *
 * Этот visitor находит вызовы чистых функций с константными аргументами
 * и заменяет их на результат выполнения в безопасном sandbox.
 *
 * Примеры трансформаций:
 * - strlen("hello") → 5
 * - abs(-42) → 42
 * - max(1, 2, 3) → 3
 * - explode(",", "a,b,c") → ["a", "b", "c"]
 * - json_encode(["a" => 1]) → '{"a":1}'
 *
 * Visitor работает только с чистыми функциями (без побочных эффектов)
 * и константными аргументами для обеспечения безопасности и детерминизма.
 */
#[VisitorMeta('Предвыполнение чистых функций/операций в compile-time через Sandbox')]
class PreExecutionVisitor extends BaseVisitor
{
    private readonly Sandbox $sandbox;

    private int $executedCount = 0;

    private int $failedCount = 0;

    private readonly ScopeStore $scopeStore;

    public function __construct(?ScopeStore $scopeStore = null)
    {
        $this->scopeStore = $scopeStore ?? ScopeStore::default();
        $cache = new ExecutionCache();
        $this->sandbox = new Sandbox($cache);
    }

    /**
     * Обрабатывает узлы при входе
     */
    public function enterNode(Node $node)
    {
        // Обрабатываем только вызовы чистых функций с константными аргументами
        if ($node instanceof Node\Expr\FuncCall) {
            return $this->handleFunctionCall($node);
        }

        // (new ReadonlyClass(constArgs))->method(constArgs) → sandbox
        if ($node instanceof Node\Expr\MethodCall) {
            return $this->handleMethodCall($node);
        }

        // (new ClassName(constArgs))->prop → sandbox evaluate
        if ($node instanceof Node\Expr\PropertyFetch) {
            return $this->handleInlineNewPropertyFetch($node);
        }

        // ClassName::method(constArgs) → sandbox (only pure multi-stmt static methods)
        if ($node instanceof Node\Expr\StaticCall) {
            return $this->handleStaticCall($node);
        }

        return null;
    }

    /**
     * Обрабатывает вызовы функций
     */
    private function handleFunctionCall(Node\Expr\FuncCall $node): ?Node
    {
        // Проверяем, что все аргументы константные
        if (!$this->hasConstantArgs($node)) {
            return null;
        }

        // Пытаемся выполнить функцию
        $preamble = $this->extractClassPreamble($node);
        $result   = $this->sandbox->execute($node, $this->buildContext(), $preamble);

        // Если выполнение успешно, заменяем вызов на результат
        if ($result instanceof SandboxResult) {
            $this->executedCount++;
            return $this->convertToNode($result->value);
        }

        $this->failedCount++;
        return null;
    }

    /**
     * Выполняет (new ReadonlyClass(constArgs))->method(constArgs) через sandbox.
     */
    private function handleMethodCall(Node\Expr\MethodCall $node): ?Node
    {
        if (!$node->var instanceof Node\Expr\New_) {
            return null;
        }

        if (!$this->isConstant($node->var)) {
            return null;
        }

        if (!array_all($node->args, fn($arg): bool => $arg instanceof Node\Arg && $this->isConstant($arg->value))) {
            return null;
        }

        $preamble = $this->extractClassPreamble($node);
        $result   = $this->sandbox->execute($node, $this->buildContext(), $preamble);

        if ($result instanceof SandboxResult) {
            $this->executedCount++;
            return $this->convertToNode($result->value);
        }

        $this->failedCount++;
        return null;
    }

    /**
     * Evaluates (new ClassName(constArgs))->prop via sandbox.
     */
    private function handleInlineNewPropertyFetch(Node\Expr\PropertyFetch $node): ?Node
    {
        if (!$node->var instanceof Node\Expr\New_) {
            return null;
        }

        if (!$node->name instanceof Node\Identifier) {
            return null;
        }

        if (!$this->isConstant($node->var)) {
            return null;
        }

        $preamble = $this->extractClassPreamble($node->var);
        $result   = $this->sandbox->execute($node, $this->buildContext(), $preamble);

        if ($result instanceof SandboxResult) {
            $this->executedCount++;
            $replacement = $this->convertToNode($result->value);
            // Inside Encapsed strings, String_ must become InterpolatedStringPart
            $parent = $node->getAttribute('parent');
            if ($parent instanceof Node\Scalar\Encapsed && $replacement instanceof Node\Scalar\String_) {
                return new Node\InterpolatedStringPart($replacement->value);
            }

            return $replacement;
        }

        $this->failedCount++;
        return null;
    }

    /**
     * Проверяет, что все аргументы функции константные
     */
    private function hasConstantArgs(Node\Expr\FuncCall $node): bool
    {
        return array_all($node->args, fn($arg): bool => $arg instanceof Node\Arg && $this->isConstant($arg->value));
    }

    /**
     * Проверяет, является ли узел константным значением
     */
    private function isConstant(Node $node): bool
    {
        // Скалярные значения
        if ($node instanceof Scalar) {
            return true;
        }

        // Константы
        if ($node instanceof Node\Expr\ConstFetch) {
            return true;
        }

        // Массивы с константными элементами
        if ($node instanceof Node\Expr\Array_) {
            foreach ($node->items as $item) {
                if ($item === null) {
                    continue;
                }

                if (!$this->isConstant($item->value)) {
                    return false;
                }

                if ($item->key !== null && !$this->isConstant($item->key)) {
                    return false;
                }
            }

            return true;
        }

        // Унарные операции с константными операндами
        if (
            $node instanceof Node\Expr\UnaryMinus
            || $node instanceof Node\Expr\UnaryPlus
            || $node instanceof Node\Expr\BitwiseNot
            || $node instanceof Node\Expr\BooleanNot
        ) {
            return $this->isConstant($node->expr);
        }

        // Бинарные операции с константными операндами
        if ($node instanceof Node\Expr\BinaryOp) {
            return $this->isConstant($node->left) && $this->isConstant($node->right);
        }

        // Стрелочная функция без захвата внешних переменных — передаём в sandbox
        if ($node instanceof Node\Expr\ArrowFunction) {
            return true;
        }

        // Замыкание без use-захватов — самодостаточно, можно передать в sandbox
        if ($node instanceof Node\Expr\Closure && $node->uses === []) {
            return true;
        }

        // new ClassName(constantArgs) — можно попытаться выполнить в sandbox
        if ($node instanceof Node\Expr\New_ && $node->class instanceof Node\Name) {
            return array_all(
                $node->args,
                fn($arg): bool => $arg instanceof Node\Arg && $this->isConstant($arg->value)
            );
        }

        return false;
    }

    /**
     * Sandbox-evaluates multi-statement static methods that are side-effect-free.
     * Single-statement methods are already handled by StaticMethodInlinerVisitor.
     */
    private function handleStaticCall(Node\Expr\StaticCall $node): ?Node
    {
        if (!$node->class instanceof Node\Name || !$node->name instanceof Node\Identifier) {
            return null;
        }

        if (!array_all($node->args, fn($a): bool => $a instanceof Node\Arg && $this->isConstant($a->value))) {
            return null;
        }

        $className  = $node->class->toString();
        $methodName = $node->name->toString();

        $method = $this->findStaticClassMethod($className, $methodName);
        if (!$method instanceof \PhpParser\Node\Stmt\ClassMethod) {
            return null;
        }

        // Single Return_ methods are handled by StaticMethodInlinerVisitor — skip to avoid duplicate work
        if (
            $method->stmts !== null
            && count($method->stmts) === 1
            && $method->stmts[0] instanceof Node\Stmt\Return_
        ) {
            return null;
        }

        if (!$this->isPureStaticMethod($method)) {
            return null;
        }

        $preamble = $this->buildClassPreambleByName($className);
        if ($preamble === '') {
            return null;
        }

        $result = $this->sandbox->execute($node, $this->buildContext(), $preamble);
        if ($result instanceof SandboxResult) {
            $this->executedCount++;
            return $this->convertToNode($result->value);
        }

        $this->failedCount++;
        return null;
    }

    private function findStaticClassMethod(string $className, string $methodName): ?Node\Stmt\ClassMethod
    {
        foreach ($this->findNode(Node\Stmt\Class_::class) as $class) {
            if (
                $class->name instanceof Node\Identifier
                && $class->name->toString() === $className
            ) {
                foreach ($class->stmts as $stmt) {
                    if (
                        $stmt instanceof Node\Stmt\ClassMethod
                        && $stmt->isStatic()
                        && $stmt->name->toString() === $methodName
                    ) {
                        return $stmt;
                    }
                }
            }
        }

        return null;
    }

    private function isPureStaticMethod(Node\Stmt\ClassMethod $method): bool
    {
        $body = $method->stmts ?? [];

        // Bail if any static property is written: self::$x = ... / static::$x[...] = ...
        foreach ($this->findNode(Node\Expr\Assign::class, $body) as $assign) {
            $var = $assign->var;
            if (
                $var instanceof Node\Expr\StaticPropertyFetch
                || ($var instanceof Node\Expr\ArrayDimFetch && $var->var instanceof Node\Expr\StaticPropertyFetch)
            ) {
                return false;
            }
        }

        // Bail on echo / print
        if (
            $this->findNode(Node\Stmt\Echo_::class, $body) !== []
            || $this->findNode(Node\Expr\Print_::class, $body) !== []
        ) {
            return false;
        }

        return true;
    }

    private function buildClassPreambleByName(string $className): string
    {
        $printer = new Printer();
        foreach ($this->findNode(Node\Stmt\Class_::class) as $class) {
            if (
                $class->name instanceof Node\Identifier
                && $class->name->toString() === $className
            ) {
                return $printer->prettyPrint([$class]);
            }
        }

        return '';
    }

    /**
     * Извлекает определения классов, на которые ссылается выражение через new ClassName(),
     * и возвращает их в виде PHP-кода для вставки в preamble sandbox.
     */
    private function extractClassPreamble(Node $node): string
    {
        $classNames = [];
        foreach ($this->findNode(Node\Expr\New_::class, [$node]) as $new) {
            if ($new->class instanceof Node\Name) {
                $classNames[] = $new->class->toString();
            }
        }

        if ($classNames === []) {
            return '';
        }

        $printer  = new Printer();
        $preamble = '';
        foreach ($this->findNode(Node\Stmt\Class_::class) as $class) {
            if (
                $class->name instanceof Node\Identifier
                && in_array($class->name->toString(), $classNames, true)
            ) {
                $preamble .= $printer->prettyPrint([$class]) . "\n";
            }
        }

        return $preamble;
    }

    /**
     * Конвертирует PHP значение в AST узел
     */
    private function convertToNode(mixed $value): Node
    {
        // Null
        if ($value === null) {
            return new Node\Expr\ConstFetch(new Node\Name('null'));
        }

        // Boolean
        if (is_bool($value)) {
            return new Node\Expr\ConstFetch(
                new Node\Name($value ? 'true' : 'false')
            );
        }

        // Integer
        if (is_int($value)) {
            return new Scalar\Int_($value);
        }

        // Float
        if (is_float($value)) {
            return new Scalar\Float_($value);
        }

        // String
        if (is_string($value)) {
            return new Scalar\String_($value);
        }

        // Array
        if (is_array($value)) {
            $items = [];
            foreach ($value as $key => $val) {
                $items[] = new Node\ArrayItem(
                    $this->convertToNode($val),
                    is_string($key) ? new Scalar\String_($key) : new Scalar\Int_($key)
                );
            }

            return new Node\Expr\Array_($items);
        }

        // Для неподдерживаемых типов возвращаем null constant
        return new Node\Expr\ConstFetch(new Node\Name('null'));
    }

    /**
     * Строит контекст выполнения из текущего scope
     *
     * @return array<string, mixed>
     */
    private function buildContext(): array
    {
        $context = [];

        // Добавляем глобальные константы; AST-узлы (Node\Scalar) раскрываем до PHP-значений
        $globalConsts = $this->scopeStore->getAllGlobalConsts();
        foreach ($globalConsts as $name => $value) {
            if ($value instanceof Node\Scalar) {
                $context[$name] = $value->value;
            } elseif (is_scalar($value) || $value === null) {
                $context[$name] = $value;
            }

            // Пропускаем всё остальное (AST-объекты с круговыми ссылками)
        }

        return $context;
    }

    /**
     * Возвращает статистику выполнения
     *
     * @return array<mixed>
     */
    public function getStats(): array
    {
        return [
            'executed' => $this->executedCount,
            'failed' => $this->failedCount,
            'total' => $this->executedCount + $this->failedCount,
        ];
    }

    /**
     * Возвращает статистику кеша
     *
     * @return array<mixed>
     */
    public function getCacheStats(): array
    {
        return $this->sandbox->getCacheStats();
    }

    #[\Override]
    public function afterTraverse(array $nodes): ?array
    {
        if ($this->executedCount > 0 || $this->failedCount > 0) {
            $total = $this->executedCount + $this->failedCount;
            $successRate = $total > 0 ? ($this->executedCount / $total) * 100 : 0;

            // Можно раскомментировать для отладки
            // echo sprintf(
            //     "PreExecution: %d успешно, %d неудачно (%.1f%% успеха)\n",
            //     $this->executedCount,
            //     $this->failedCount,
            //     $successRate
            // );
        }

        return null;
    }
}
