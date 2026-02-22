<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;
use PhpParser\Node\Scalar;
use Recombinator\Core\ExecutionCache;
use Recombinator\Support\Sandbox;
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
        $result = $this->sandbox->execute($node, $this->buildContext());

        // Если выполнение успешно, заменяем вызов на результат
        if ($result !== null && !$this->sandbox->isError($result)) {
            $this->executedCount++;
            return $this->convertToNode($result);
        }

        $this->failedCount++;
        return null;
    }

    /**
     * Проверяет, что все аргументы функции константные
     */
    private function hasConstantArgs(Node\Expr\FuncCall $node): bool
    {
        return array_all($node->args, fn($arg): bool => $this->isConstant($arg->value));
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

        return false;
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
