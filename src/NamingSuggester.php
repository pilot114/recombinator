<?php

declare(strict_types=1);

namespace Recombinator;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;

/**
 * Генератор подсказок для именования переменных и функций
 *
 * Анализирует контекст использования и предлагает осмысленные имена
 * на основе типа операции, используемых функций и паттернов.
 */
class NamingSuggester
{
    /**
     * Генерирует предложения для имени переменной на основе её значения
     *
     * @param Node $valueNode Узел, представляющий значение переменной
     * @return array<string> Список предложенных имён
     */
    public function suggestVariableName(Node $valueNode): array
    {
        $suggestions = [];

        // Анализ по типу выражения
        if ($valueNode instanceof Expr\BinaryOp) {
            $suggestions = array_merge($suggestions, $this->suggestForBinaryOp($valueNode));
        } elseif ($valueNode instanceof Expr\FuncCall) {
            $suggestions = array_merge($suggestions, $this->suggestForFunctionCall($valueNode));
        } elseif ($valueNode instanceof Expr\MethodCall) {
            $suggestions = array_merge($suggestions, $this->suggestForMethodCall($valueNode));
        } elseif ($valueNode instanceof Expr\ArrayDimFetch) {
            $suggestions = array_merge($suggestions, $this->suggestForArrayAccess($valueNode));
        } elseif ($valueNode instanceof Expr\Ternary) {
            $suggestions = array_merge($suggestions, $this->suggestForTernary($valueNode));
        } elseif ($valueNode instanceof Expr\Array_) {
            $suggestions[] = 'items';
            $suggestions[] = 'data';
            $suggestions[] = 'list';
        }

        return array_unique($suggestions);
    }

    /**
     * Генерирует предложения для имени функции на основе её тела
     *
     * @param Node[] $body Узлы тела функции
     * @param SideEffectType $effectType Тип побочного эффекта
     * @return array<string> Список предложенных имён
     */
    public function suggestFunctionName(array $body, SideEffectType $effectType): array
    {
        $suggestions = [];

        // Предложения на основе типа эффекта
        $suggestions = array_merge($suggestions, $this->suggestByEffectType($effectType));

        // Анализ операций в теле функции
        foreach ($body as $node) {
            if ($node instanceof Stmt\Echo_) {
                $suggestions[] = 'display';
                $suggestions[] = 'output';
                $suggestions[] = 'print';
            } elseif ($node instanceof Stmt\Return_) {
                $suggestions[] = 'calculate';
                $suggestions[] = 'compute';
                $suggestions[] = 'get';
            } elseif ($node instanceof Expr\Assign) {
                $suggestions[] = 'process';
                $suggestions[] = 'prepare';
            }
        }

        return array_unique($suggestions);
    }

    /**
     * Проверяет, является ли имя плохим (требует улучшения)
     */
    public function isPoorName(string $name): bool
    {
        // Слишком короткие имена (кроме стандартных)
        if (strlen($name) <= 2 && !in_array($name, ['i', 'j', 'k', 'id', 'db'])) {
            return true;
        }

        // Общие плохие имена
        $badNames = [
            'tmp', 'temp', 'data', 'value', 'var', 'obj', 'arr',
            'foo', 'bar', 'baz', 'test', 'asdf', 'qwerty',
            'x', 'y', 'z', 'a', 'b', 'c',
        ];

        if (in_array(strtolower($name), $badNames)) {
            return true;
        }

        // Только числа или бессмысленные комбинации
        if (preg_match('/^[a-z]\d+$/', $name)) {
            return true;
        }

        return false;
    }

    /**
     * Оценивает качество имени (0-10)
     */
    public function scoreNameQuality(string $name): int
    {
        $score = 5; // базовая оценка

        // Длина имени
        $len = strlen($name);
        if ($len >= 3 && $len <= 20) {
            $score += 2;
        } elseif ($len > 20) {
            $score -= 1;
        }

        // camelCase или snake_case
        if (preg_match('/^[a-z][a-zA-Z0-9]*$/', $name) || preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
            $score += 1;
        }

        // Содержит осмысленные слова
        if ($this->containsMeaningfulWords($name)) {
            $score += 2;
        }

        // Плохие имена
        if ($this->isPoorName($name)) {
            $score -= 5;
        }

        return max(0, min(10, $score));
    }

    private function suggestForBinaryOp(Expr\BinaryOp $node): array
    {
        if ($node instanceof Expr\BinaryOp\Plus || $node instanceof Expr\BinaryOp\Minus) {
            return ['sum', 'total', 'result', 'value'];
        }

        if ($node instanceof Expr\BinaryOp\Mul || $node instanceof Expr\BinaryOp\Div) {
            return ['product', 'result', 'calculated'];
        }

        if ($node instanceof Expr\BinaryOp\Concat) {
            return ['text', 'message', 'output', 'combined'];
        }

        if ($node instanceof Expr\BinaryOp\BooleanAnd || $node instanceof Expr\BinaryOp\BooleanOr) {
            return ['isValid', 'condition', 'check', 'flag'];
        }

        if ($node instanceof Expr\BinaryOp\Equal || $node instanceof Expr\BinaryOp\Identical) {
            return ['isEqual', 'matches', 'isSame'];
        }

        if ($node instanceof Expr\BinaryOp\Greater || $node instanceof Expr\BinaryOp\Smaller) {
            return ['isGreater', 'isLess', 'comparison'];
        }

        return ['result'];
    }

    private function suggestForFunctionCall(Expr\FuncCall $node): array
    {
        if (!$node->name instanceof Node\Name) {
            return [];
        }

        $funcName = $node->name->toString();
        $suggestions = [];

        // Анализ известных функций
        $functionMap = [
            'count' => ['count', 'total', 'size', 'length'],
            'strlen' => ['length', 'size'],
            'array_merge' => ['merged', 'combined', 'items'],
            'array_filter' => ['filtered', 'valid', 'selected'],
            'array_map' => ['mapped', 'transformed', 'converted'],
            'explode' => ['parts', 'pieces', 'segments'],
            'implode' => ['joined', 'combined', 'text'],
            'trim' => ['trimmed', 'cleaned', 'sanitized'],
            'strtolower' => ['lowercase', 'normalized'],
            'strtoupper' => ['uppercase'],
            'json_decode' => ['decoded', 'data', 'parsed'],
            'json_encode' => ['encoded', 'json', 'serialized'],
        ];

        foreach ($functionMap as $pattern => $names) {
            if (str_contains($funcName, $pattern)) {
                $suggestions = array_merge($suggestions, $names);
            }
        }

        return $suggestions;
    }

    private function suggestForMethodCall(Expr\MethodCall $node): array
    {
        if (!$node->name instanceof Node\Identifier) {
            return [];
        }

        $methodName = $node->name->toString();
        $suggestions = [];

        // Геттеры
        if (str_starts_with($methodName, 'get')) {
            $property = lcfirst(substr($methodName, 3));
            $suggestions[] = $property;
        }

        // Проверки
        if (str_starts_with($methodName, 'is') || str_starts_with($methodName, 'has')) {
            $suggestions[] = lcfirst($methodName);
        }

        return $suggestions;
    }

    private function suggestForArrayAccess(Expr\ArrayDimFetch $node): array
    {
        $suggestions = [];

        // Доступ к суперглобалам
        if ($node->var instanceof Expr\Variable) {
            $varName = $node->var->name;
            if (is_string($varName)) {
                $map = [
                    '_GET' => ['param', 'query', 'input'],
                    '_POST' => ['data', 'input', 'posted'],
                    '_SESSION' => ['sessionData', 'userData'],
                    '_SERVER' => ['serverVar', 'envVar'],
                    '_COOKIE' => ['cookieValue'],
                ];

                if (isset($map[$varName])) {
                    $suggestions = array_merge($suggestions, $map[$varName]);
                }
            }
        }

        // Если ключ - строка, используем его
        if ($node->dim instanceof Node\Scalar\String_) {
            $key = $node->dim->value;
            $suggestions[] = $this->toCamelCase($key);
        }

        return $suggestions;
    }

    private function suggestForTernary(Expr\Ternary $node): array
    {
        return ['result', 'value', 'chosen', 'selected'];
    }

    private function suggestByEffectType(SideEffectType $effectType): array
    {
        return match ($effectType) {
            SideEffectType::PURE => ['calculate', 'compute', 'process', 'transform'],
            SideEffectType::IO => ['display', 'output', 'print', 'write'],
            SideEffectType::EXTERNAL_STATE => ['fetchInput', 'getParam', 'readState'],
            SideEffectType::DATABASE => ['queryDb', 'fetchData', 'loadRecords'],
            SideEffectType::HTTP => ['makeRequest', 'fetchRemote', 'callApi'],
            SideEffectType::NON_DETERMINISTIC => ['generate', 'getRandom', 'getCurrentTime'],
            default => ['process', 'execute', 'perform'],
        };
    }

    private function containsMeaningfulWords(string $name): bool
    {
        $meaningfulWords = [
            'user', 'data', 'item', 'list', 'result', 'value', 'name', 'id',
            'count', 'total', 'sum', 'index', 'key', 'content', 'message',
            'status', 'type', 'code', 'error', 'success', 'valid', 'check',
        ];

        $nameLower = strtolower($name);
        foreach ($meaningfulWords as $word) {
            if (str_contains($nameLower, $word)) {
                return true;
            }
        }

        return false;
    }

    private function toCamelCase(string $str): string
    {
        $str = str_replace(['-', '_'], ' ', $str);
        $str = ucwords($str);
        $str = str_replace(' ', '', $str);
        return lcfirst($str);
    }
}
