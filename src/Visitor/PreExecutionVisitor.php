<?php

declare(strict_types=1);

namespace Recombinator\Visitor;

use PhpParser\Node;
use PhpParser\Node\Scalar;
use Recombinator\ExecutionCache;
use Recombinator\Sandbox;
use Recombinator\ScopeStore;

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
class PreExecutionVisitor extends BaseVisitor
{
    private Sandbox $sandbox;
    private ScopeStore $scopeStore;
    private int $executedCount = 0;
    private int $failedCount = 0;

    public function __construct(?ScopeStore $scopeStore = null)
    {
        $cache = new ExecutionCache();
        $this->sandbox = new Sandbox($cache);
        $this->scopeStore = $scopeStore ?? new ScopeStore();
    }

    /**
     * Обрабатывает узлы при входе
     */
    public function enterNode(Node $node)
    {
        // Обрабатываем вызовы функций
        if ($node instanceof Node\Expr\FuncCall) {
            return $this->handleFunctionCall($node);
        }

        // Обрабатываем бинарные операции
        if ($node instanceof Node\Expr\BinaryOp) {
            return $this->handleBinaryOp($node);
        }

        // Обрабатываем унарные операции
        if ($node instanceof Node\Expr\UnaryOp) {
            return $this->handleUnaryOp($node);
        }

        // Обрабатываем тернарные операции с константными условиями
        if ($node instanceof Node\Expr\Ternary) {
            return $this->handleTernary($node);
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
     * Обрабатывает бинарные операции
     */
    private function handleBinaryOp(Node\Expr\BinaryOp $node): ?Node
    {
        // Проверяем, что оба операнда константные
        if (!$this->isConstant($node->left) || !$this->isConstant($node->right)) {
            return null;
        }

        // Пытаемся выполнить операцию
        $result = $this->sandbox->execute($node, $this->buildContext());

        if ($result !== null && !$this->sandbox->isError($result)) {
            $this->executedCount++;
            return $this->convertToNode($result);
        }

        $this->failedCount++;
        return null;
    }

    /**
     * Обрабатывает унарные операции
     */
    private function handleUnaryOp(Node\Expr\UnaryOp $node): ?Node
    {
        // Проверяем, что операнд константный
        if (!$this->isConstant($node->expr)) {
            return null;
        }

        // Пытаемся выполнить операцию
        $result = $this->sandbox->execute($node, $this->buildContext());

        if ($result !== null && !$this->sandbox->isError($result)) {
            $this->executedCount++;
            return $this->convertToNode($result);
        }

        $this->failedCount++;
        return null;
    }

    /**
     * Обрабатывает тернарные операции
     */
    private function handleTernary(Node\Expr\Ternary $node): ?Node
    {
        // Проверяем, что условие константное
        if (!$this->isConstant($node->cond)) {
            return null;
        }

        // Вычисляем условие
        $condResult = $this->sandbox->execute($node->cond, $this->buildContext());

        if ($condResult === null || $this->sandbox->isError($condResult)) {
            return null;
        }

        // Возвращаем соответствующую ветку
        $this->executedCount++;
        if ($condResult) {
            return $node->if ?? $node->cond;
        } else {
            return $node->else;
        }
    }

    /**
     * Проверяет, что все аргументы функции константные
     */
    private function hasConstantArgs(Node\Expr\FuncCall $node): bool
    {
        foreach ($node->args as $arg) {
            if (!$this->isConstant($arg->value)) {
                return false;
            }
        }
        return true;
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
        if ($node instanceof Node\Expr\UnaryOp) {
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
     */
    private function buildContext(): array
    {
        $context = [];

        // Добавляем глобальные константы
        $globalConsts = $this->scopeStore->getAllGlobalConsts();
        foreach ($globalConsts as $name => $value) {
            $context[$name] = $value;
        }

        return $context;
    }

    /**
     * Возвращает статистику выполнения
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
     */
    public function getCacheStats(): array
    {
        return $this->sandbox->getCacheStats();
    }

    public function afterTraverse(array $nodes)
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
    }
}
