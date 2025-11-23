<?php

declare(strict_types=1);

namespace Recombinator\Domain;

/**
 * Результат разделения побочных эффектов
 */
class SeparationResult
{
    /**
     * @param array<string, EffectGroup> $groups Группы узлов по типу эффекта
     * @param PureComputation[] $pureComputations Чистые вычисления
     * @param EffectBoundary[] $boundaries Границы между эффектами
     * @param array $pureBlocks Чистые блоки из PureBlockFinder
     * @param EffectDependencyGraph $dependencyGraph Граф зависимостей
     * @param array $stats Статистика
     */
    public function __construct(
        public readonly array $groups,
        public readonly array $pureComputations,
        public readonly array $boundaries,
        public readonly array $pureBlocks,
        public readonly EffectDependencyGraph $dependencyGraph,
        public readonly array $stats,
    ) {}

    /**
     * Возвращает группы узлов по типу эффекта
     *
     * @return array<string, EffectGroup>
     */
    public function getGroups(): array
    {
        return $this->groups;
    }

    /**
     * Возвращает чистые вычисления
     *
     * @return PureComputation[]
     */
    public function getPureComputations(): array
    {
        return $this->pureComputations;
    }

    /**
     * Возвращает границы между эффектами
     *
     * @return EffectBoundary[]
     */
    public function getBoundaries(): array
    {
        return $this->boundaries;
    }

    /**
     * Возвращает граф зависимостей
     */
    public function getDependencyGraph(): EffectDependencyGraph
    {
        return $this->dependencyGraph;
    }

    /**
     * Возвращает статистику
     */
    public function getStats(): array
    {
        return $this->stats;
    }

    /**
     * Возвращает группу по типу эффекта
     */
    public function getGroupByEffect(SideEffectType $effect): ?EffectGroup
    {
        return $this->groups[$effect->value] ?? null;
    }

    /**
     * Возвращает количество переходов между эффектами
     */
    public function getBoundaryCount(): int
    {
        return count($this->boundaries);
    }

    /**
     * Возвращает чистые вычисления, которые можно вычислить в compile-time
     *
     * @return PureComputation[]
     */
    public function getCompileTimeEvaluableComputations(): array
    {
        return array_filter(
            $this->pureComputations,
            fn($c) => $c->isCompileTimeEvaluable
        );
    }
}
