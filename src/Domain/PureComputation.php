<?php

declare(strict_types=1);

namespace Recombinator\Domain;

use PhpParser\Node;

/**
 * Чистое вычисление без побочных эффектов
 *
 * Представляет блок кода, состоящий из детерминированных операций без побочных эффектов.
 * Такие вычисления могут быть безопасно переупорядочены, кешированы или вычислены
 * на этапе компиляции (compile-time evaluation).
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
     * @param Node[]  $nodes         Узлы чистого
     *                               вычисления
     * @param integer $startPosition Начальная
     *                               позиция
     * @param integer $endPosition   Конечная
     *                               позиция
     * @param integer $size          Количество
     *                               узлов
     * @param string  $id            Уникальный
     *                               идентификатор
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
