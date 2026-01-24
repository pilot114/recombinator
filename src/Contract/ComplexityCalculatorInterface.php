<?php

declare(strict_types=1);

namespace Recombinator\Contract;

use PhpParser\Node;

/**
 * Интерфейс для калькуляторов сложности кода
 *
 * Определяет контракт для вычисления различных метрик сложности
 * (когнитивной, цикломатической и т.д.). Используется для оценки
 * сложности блоков кода и принятия решений об их рефакторинге.
 */
interface ComplexityCalculatorInterface
{
    /**
     * Вычисляет сложность для заданных узлов
     *
     * @param  array<Node> $nodes Узлы для анализа
     * @return integer Значение сложности
     */
    public function calculate(array $nodes): int;

    /**
     * Определяет уровень сложности
     *
     * @param  integer $value Значение сложности
     * @return string Уровень (low, moderate, high, very high)
     */
    public function getComplexityLevel(int $value): string;
}
