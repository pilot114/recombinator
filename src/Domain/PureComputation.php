<?php

declare(strict_types=1);

namespace Recombinator\Domain;

use PhpParser\Node;

/**
 * Чистое вычисление
 */
class PureComputation
{
    /**
     * Зависимости (ID узлов из графа зависимостей)
     *
     * @var string[]
     */
    public array $dependencies = [];

    /**
     * Можно ли вычислить в compile-time
     */
    public bool $isCompileTimeEvaluable = false;

    /**
     * @param Node[] $nodes         Узлы чистого
     *                              вычисления
     * @param int    $startPosition Начальная
     *                              позиция
     * @param int    $endPosition   Конечная
     *                              позиция
     * @param int    $size          Количество
     *                              узлов
     * @param string $id            Уникальный
     *                              идентификатор
     */
    public function __construct(
        public readonly array $nodes,
        public readonly int $startPosition,
        public readonly int $endPosition,
        public readonly int $size,
        public readonly string $id,
    ) {
    }

    /**
     * Проверяет, зависит ли вычисление от внешних узлов
     */
    public function hasDependencies(): bool
    {
        return $this->dependencies !== [];
    }

    /**
     * Возвращает количество зависимостей
     */
    public function getDependencyCount(): int
    {
        return count($this->dependencies);
    }
}
