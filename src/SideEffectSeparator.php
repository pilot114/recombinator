<?php

declare(strict_types=1);

namespace Recombinator;

use PhpParser\Node;

/**
 * Разделение побочных эффектов (Phase 3.3)
 *
 * Основной компонент для разделения кода по типам побочных эффектов.
 * Использует результаты работы SideEffectMarkerVisitor, EffectDependencyGraph
 * и PureBlockFinder для:
 *
 * 1. Группировки узлов по типу эффекта
 * 2. Выделения чистых вычислений
 * 3. Минимизации точек взаимодействия между эффектами
 *
 * Результат разделения используется для:
 * - Фазы свёртки (создание новых абстракций по типу эффекта)
 * - Оптимизации порядка выполнения
 * - Улучшения читаемости кода
 *
 * Пример использования:
 * ```php
 * // Подготовка AST
 * $marker = new SideEffectMarkerVisitor();
 * $traverser = new NodeTraverser();
 * $traverser->addVisitor($marker);
 * $ast = $traverser->traverse($ast);
 *
 * // Разделение
 * $separator = new SideEffectSeparator();
 * $result = $separator->separate($ast);
 *
 * // Результаты
 * $groups = $result->getGroups();           // Группы по типу эффекта
 * $pure = $result->getPureComputations();   // Чистые вычисления
 * $boundaries = $result->getBoundaries();   // Границы между эффектами
 * ```
 */
class SideEffectSeparator
{
    private EffectDependencyGraph $dependencyGraph;
    private PureBlockFinder $pureBlockFinder;

    /**
     * Минимальный размер чистого блока для выделения
     */
    private int $minPureBlockSize;

    public function __construct(
        ?EffectDependencyGraph $dependencyGraph = null,
        ?PureBlockFinder $pureBlockFinder = null,
        int $minPureBlockSize = 1
    ) {
        $this->dependencyGraph = $dependencyGraph ?? new EffectDependencyGraph();
        $this->pureBlockFinder = $pureBlockFinder ?? new PureBlockFinder($minPureBlockSize);
        $this->minPureBlockSize = $minPureBlockSize;
    }

    /**
     * Разделяет код по типам побочных эффектов
     *
     * @param Node[] $ast AST, обработанный SideEffectMarkerVisitor
     * @return SeparationResult Результат разделения
     */
    public function separate(array $ast): SeparationResult
    {
        // 1. Строим граф зависимостей
        $this->dependencyGraph->buildFromAST($ast);

        // 2. Находим чистые блоки
        $pureBlocks = $this->pureBlockFinder->findBlocks($ast);

        // 3. Группируем узлы по типу эффекта
        $groups = $this->groupByEffect($ast);

        // 4. Находим границы между эффектами
        $boundaries = $this->findBoundaries($ast);

        // 5. Минимизируем точки взаимодействия
        $optimizedGroups = $this->minimizeInteractions($groups, $boundaries);

        // 6. Выделяем чистые вычисления
        $pureComputations = $this->extractPureComputations($pureBlocks, $groups);

        return new SeparationResult(
            groups: $optimizedGroups,
            pureComputations: $pureComputations,
            boundaries: $boundaries,
            pureBlocks: $pureBlocks,
            dependencyGraph: $this->dependencyGraph,
            stats: $this->calculateStats($optimizedGroups, $pureComputations)
        );
    }

    /**
     * Группирует узлы по типу побочного эффекта
     *
     * @param Node[] $ast
     * @return array<string, EffectGroup>
     */
    private function groupByEffect(array $ast): array
    {
        $groups = [];

        // Получаем группировку из графа зависимостей
        $nodesByEffect = $this->dependencyGraph->getNodesByEffect();

        foreach ($nodesByEffect as $effectType => $nodeIds) {
            $nodes = [];
            foreach ($nodeIds as $nodeId) {
                $nodeData = $this->dependencyGraph->getNodes()[$nodeId] ?? null;
                if ($nodeData) {
                    $nodes[] = $nodeData['node'];
                }
            }

            $effectEnum = SideEffectType::from($effectType);
            $groups[$effectType] = new EffectGroup(
                effect: $effectEnum,
                nodes: $nodes,
                priority: $effectEnum->getPriority()
            );
        }

        // Сортируем по приоритету (PURE первым)
        uasort($groups, fn($a, $b) => $a->priority <=> $b->priority);

        return $groups;
    }

    /**
     * Находит границы между разными типами эффектов
     *
     * Граница - это переход от одного типа эффекта к другому
     * в последовательности statement'ов
     *
     * @param Node[] $ast
     * @return EffectBoundary[]
     */
    private function findBoundaries(array $ast): array
    {
        $boundaries = [];
        $prevEffect = null;
        $prevIndex = 0;

        foreach ($ast as $index => $node) {
            if (!$node instanceof Node) {
                continue;
            }

            $effect = $node->getAttribute('side_effect');
            if (!$effect) {
                continue;
            }

            // Переход от одного эффекта к другому
            if ($prevEffect !== null && $prevEffect !== $effect) {
                $boundaries[] = new EffectBoundary(
                    fromEffect: $prevEffect,
                    toEffect: $effect,
                    position: $index,
                    prevPosition: $prevIndex
                );
            }

            $prevEffect = $effect;
            $prevIndex = $index;
        }

        return $boundaries;
    }

    /**
     * Минимизирует точки взаимодействия между эффектами
     *
     * Стратегия:
     * 1. Группируем последовательные узлы одного типа
     * 2. Находим узлы, которые можно безопасно переместить
     * 3. Объединяем смежные группы одного типа
     *
     * @param array<string, EffectGroup> $groups
     * @param EffectBoundary[] $boundaries
     * @return array<string, EffectGroup>
     */
    private function minimizeInteractions(array $groups, array $boundaries): array
    {
        // Подсчитываем количество переходов для каждого типа эффекта
        $transitionCounts = [];
        foreach ($boundaries as $boundary) {
            $fromType = $boundary->fromEffect->value;
            $toType = $boundary->toEffect->value;

            if (!isset($transitionCounts[$fromType])) {
                $transitionCounts[$fromType] = 0;
            }
            $transitionCounts[$fromType]++;
        }

        // Добавляем информацию о переходах в группы
        foreach ($groups as $effectType => $group) {
            $group->transitionCount = $transitionCounts[$effectType] ?? 0;
        }

        // Находим узлы, которые можно безопасно переупорядочить
        $reorderableNodes = [];
        foreach ($this->dependencyGraph->getNodes() as $nodeId => $nodeData) {
            if ($this->dependencyGraph->canReorder($nodeId)) {
                $reorderableNodes[] = $nodeId;
            }
        }

        // Добавляем информацию о переупорядочиваемых узлах в группы
        foreach ($groups as $effectType => $group) {
            $group->reorderableCount = 0;
            foreach ($group->nodes as $node) {
                $nodeId = $this->getNodeId($node);
                if (in_array($nodeId, $reorderableNodes, true)) {
                    $group->reorderableCount++;
                }
            }
        }

        return $groups;
    }

    /**
     * Выделяет чистые вычисления
     *
     * @param array $pureBlocks Чистые блоки из PureBlockFinder
     * @param array<string, EffectGroup> $groups Группы эффектов
     * @return PureComputation[]
     */
    private function extractPureComputations(array $pureBlocks, array $groups): array
    {
        $computations = [];

        // Обрабатываем каждый чистый блок
        foreach ($pureBlocks as $blockIndex => $block) {
            $computation = new PureComputation(
                nodes: $block['nodes'],
                startPosition: $block['start'],
                endPosition: $block['end'],
                size: $block['size'],
                id: 'pure_block_' . $blockIndex
            );

            // Анализируем зависимости
            $dependencies = [];
            foreach ($block['nodes'] as $node) {
                $nodeId = $this->getNodeId($node);
                $nodeDeps = $this->dependencyGraph->getDependencies($nodeId);
                $dependencies = array_merge($dependencies, $nodeDeps);
            }
            $computation->dependencies = array_unique($dependencies);

            // Проверяем, можно ли вычислить в compile-time
            $computation->isCompileTimeEvaluable = $this->canEvaluateAtCompileTime($block['nodes']);

            $computations[] = $computation;
        }

        return $computations;
    }

    /**
     * Проверяет, можно ли вычислить блок в compile-time
     *
     * @param Node[] $nodes
     */
    private function canEvaluateAtCompileTime(array $nodes): bool
    {
        foreach ($nodes as $node) {
            $effect = $node->getAttribute('side_effect');
            if (!$effect || !$effect->isCompileTimeEvaluable()) {
                return false;
            }

            // Проверяем зависимости
            $nodeId = $this->getNodeId($node);
            $dependencies = $this->dependencyGraph->getDependencies($nodeId);

            foreach ($dependencies as $depId) {
                $depNode = $this->dependencyGraph->getNodes()[$depId] ?? null;
                if (!$depNode || !$depNode['effect']->isCompileTimeEvaluable()) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Получает ID узла (совместимо с EffectDependencyGraph)
     */
    private function getNodeId(Node $node): string
    {
        return sprintf(
            '%s_%d_%d',
            $node->getType(),
            $node->getStartFilePos() ?? 0,
            $node->getEndFilePos() ?? 0
        );
    }

    /**
     * Вычисляет статистику разделения
     *
     * @param array<string, EffectGroup> $groups
     * @param PureComputation[] $pureComputations
     * @return array
     */
    private function calculateStats(array $groups, array $pureComputations): array
    {
        $totalNodes = 0;
        $effectCounts = [];

        foreach ($groups as $effectType => $group) {
            $count = count($group->nodes);
            $totalNodes += $count;
            $effectCounts[$effectType] = $count;
        }

        $totalPureNodes = array_sum(array_map(fn($c) => $c->size, $pureComputations));

        return [
            'total_nodes' => $totalNodes,
            'total_groups' => count($groups),
            'effect_counts' => $effectCounts,
            'total_pure_computations' => count($pureComputations),
            'total_pure_nodes' => $totalPureNodes,
            'pure_percentage' => $totalNodes > 0 ? round(($totalPureNodes / $totalNodes) * 100, 2) : 0,
            'compile_time_evaluable' => count(array_filter($pureComputations, fn($c) => $c->isCompileTimeEvaluable)),
        ];
    }
}

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

/**
 * Группа узлов одного типа эффекта
 */
class EffectGroup
{
    /**
     * Количество переходов от этого эффекта к другим
     */
    public int $transitionCount = 0;

    /**
     * Количество узлов, которые можно безопасно переупорядочить
     */
    public int $reorderableCount = 0;

    /**
     * @param SideEffectType $effect Тип эффекта
     * @param Node[] $nodes Узлы этого типа
     * @param int $priority Приоритет (из SideEffectType::getPriority())
     */
    public function __construct(
        public readonly SideEffectType $effect,
        public readonly array $nodes,
        public readonly int $priority,
    ) {}

    /**
     * Возвращает количество узлов в группе
     */
    public function getSize(): int
    {
        return count($this->nodes);
    }

    /**
     * Проверяет, является ли группа чистой
     */
    public function isPure(): bool
    {
        return $this->effect->isPure();
    }

    /**
     * Возвращает процент узлов, которые можно переупорядочить
     */
    public function getReorderablePercentage(): float
    {
        $size = $this->getSize();
        return $size > 0 ? round(($this->reorderableCount / $size) * 100, 2) : 0;
    }
}

/**
 * Граница между разными типами эффектов
 */
class EffectBoundary
{
    public function __construct(
        public readonly SideEffectType $fromEffect,
        public readonly SideEffectType $toEffect,
        public readonly int $position,
        public readonly int $prevPosition,
    ) {}

    /**
     * Возвращает расстояние между границами
     */
    public function getDistance(): int
    {
        return $this->position - $this->prevPosition;
    }

    /**
     * Проверяет, является ли переход от чистого к нечистому
     */
    public function isPureToImpure(): bool
    {
        return $this->fromEffect->isPure() && !$this->toEffect->isPure();
    }

    /**
     * Проверяет, является ли переход от нечистого к чистому
     */
    public function isImpureToPure(): bool
    {
        return !$this->fromEffect->isPure() && $this->toEffect->isPure();
    }
}

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
     * @param Node[] $nodes Узлы чистого вычисления
     * @param int $startPosition Начальная позиция
     * @param int $endPosition Конечная позиция
     * @param int $size Количество узлов
     * @param string $id Уникальный идентификатор
     */
    public function __construct(
        public readonly array $nodes,
        public readonly int $startPosition,
        public readonly int $endPosition,
        public readonly int $size,
        public readonly string $id,
    ) {}

    /**
     * Проверяет, зависит ли вычисление от внешних узлов
     */
    public function hasDependencies(): bool
    {
        return !empty($this->dependencies);
    }

    /**
     * Возвращает количество зависимостей
     */
    public function getDependencyCount(): int
    {
        return count($this->dependencies);
    }
}
