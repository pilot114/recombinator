<?php

declare(strict_types=1);

namespace Recombinator\Domain;

use PhpParser\Node;

/**
 * Кандидат на выделение в функцию
 *
 * Представляет блок кода, который может быть выделен в отдельную функцию
 * согласно правилам FOLD-FUNC-1 и FOLD-FUNC-2 из docs/rules.md
 */
class FunctionCandidate
{
    /**
     * @param Node[]         $nodes            Узлы
     *                                         AST,
     *                                         составляющие
     *                                         блок
     * @param SideEffectType $effectType       Тип побочного
     *                                         эффекта блока
     * @param int            $size             Размер
     *                                         блока
     *                                         (количество
     *                                         строк/узлов)
     * @param int            $complexity       Когнитивная
     *                                         сложность
     *                                         блока
     * @param array<string>  $usedVariables    Используемые
     *                                         переменные
     *                                         (параметры
     *                                         функции)
     * @param array<string>  $definedVariables Определенные
     *                                         переменные
     *                                         (внутренние)
     * @param ?string        $returnVariable   Переменная-результат
     *                                         (если есть)
     * @param int            $startLine        Начальная
     *                                         строка в
     *                                         исходном
     *                                         коде
     * @param int            $endLine          Конечная
     *                                         строка в
     *                                         исходном
     *                                         коде
     */
    public function __construct(
        public readonly array $nodes,
        public readonly SideEffectType $effectType,
        public readonly int $size,
        public readonly int $complexity,
        public readonly array $usedVariables,
        public readonly array $definedVariables,
        public readonly ?string $returnVariable = null,
        public readonly int $startLine = 0,
        public readonly int $endLine = 0,
    ) {
    }

    /**
     * Проверяет, соответствует ли блок правилу FOLD-FUNC-1 (чистый блок)
     */
    public function matchesPureBlockRule(): bool
    {
        // FOLD-FUNC-1: PURE блок размером ≥ 5 строк
        return $this->effectType === SideEffectType::PURE
            && $this->size >= 5;
    }

    /**
     * Проверяет, соответствует ли блок правилу FOLD-FUNC-2 (блок с эффектами)
     */
    public function matchesEffectBlockRule(): bool
    {
        // FOLD-FUNC-2: блок с эффектами одного типа, размер ≥ 3 строк
        return $this->effectType !== SideEffectType::PURE
            && $this->effectType !== SideEffectType::MIXED
            && $this->size >= 3;
    }

    /**
     * Проверяет, является ли блок кандидатом на выделение в функцию
     */
    public function isViable(): bool
    {
        if ($this->matchesPureBlockRule()) {
            return true;
        }

        return $this->matchesEffectBlockRule();
    }

    /**
     * Вычисляет приоритет кандидата (выше = лучше)
     */
    public function getPriority(): int
    {
        $priority = 0;

        // Бонус за чистоту (чистые блоки приоритетнее)
        if ($this->effectType->isPure()) {
            $priority += 100;
        }

        // Бонус за размер (больше = лучше)
        $priority += $this->size * 10;

        // Бонус за сложность (сложнее = важнее вынести)
        $priority += $this->complexity * 5;

        // Штраф за большое количество параметров
        $priority -= count($this->usedVariables) * 3;

        return $priority;
    }

    /**
     * Генерирует предлагаемое имя функции на основе контекста
     */
    public function suggestFunctionName(): string
    {
        // Базовое имя зависит от типа эффекта
        $prefix = match ($this->effectType) {
            SideEffectType::PURE => 'calculate',
            SideEffectType::IO => 'print',
            SideEffectType::EXTERNAL_STATE => 'get',
            SideEffectType::DATABASE => 'query',
            SideEffectType::HTTP => 'fetch',
            SideEffectType::NON_DETERMINISTIC => 'generate',
            default => 'process',
        };

        // Если есть возвращаемая переменная, используем её имя
        if ($this->returnVariable) {
            $varName = ltrim($this->returnVariable, '$');
            return $prefix . ucfirst($varName);
        }

        // Иначе используем номер строки для уникальности
        return $prefix . 'Block' . $this->startLine;
    }

    /**
     * Возвращает массив параметров функции
     */
    public function getFunctionParameters(): array
    {
        return array_values(
            array_diff(
                $this->usedVariables,
                $this->definedVariables
            )
        );
    }
}
