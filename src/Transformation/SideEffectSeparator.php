<?php

declare(strict_types=1);

namespace Recombinator\Transformation;

use PhpParser\Node;
use Recombinator\Analysis\EffectDependencyGraph;
use Recombinator\Analysis\PureBlockFinder;
use Recombinator\Domain\SeparationResult;
use Recombinator\Domain\SideEffectType;
use Recombinator\Domain\EffectGroup;
use Recombinator\Domain\EffectBoundary;
use Recombinator\Domain\PureComputation;

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
    private readonly PureBlockFinder $pureBlockFinder;

    public function __construct(
        private readonly ?EffectDependencyGraph $dependencyGraph = new EffectDependencyGraph(),
        ?PureBlockFinder $pureBlockFinder = null,
        int $minPureBlockSize = 1
    ) {
        $this->pureBlockFinder = $pureBlockFinder ?? new PureBlockFinder($minPureBlockSize);
    }

    /**
     * Разделяет код по типам побочных эффектов
     *
     * @param  Node[] $ast AST, обработанный SideEffectMarkerVisitor
     * @return SeparationResult Результат разделения
     */
    public function separate(array $ast): SeparationResult
    {
        // 1. Строим граф зависимостей
        $this->dependencyGraph->buildFromAST($ast);

        // 2. Находим чистые блоки
        $pureBlocks = $this->pureBlockFinder->findBlocks($ast);

        // 3. Группируем узлы по типу эффекта
        $groups = $this->groupByEffect();

        // 4. Находим границы между эффектами
        $boundaries = $this->findBoundaries($ast);

        // 5. Минимизируем точки взаимодействия
        $optimizedGroups = $this->minimizeInteractions($groups, $boundaries);

        // 6. Выделяем чистые вычисления
        $pureComputations = $this->extractPureComputations($pureBlocks);

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
     * @return array<string, EffectGroup>
     */
    private function groupByEffect(): array
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
        uasort($groups, fn($a, $b): int => $a->priority <=> $b->priority);
        return $groups;
    }

    /**
     * Находит границы между разными типами эффектов
     *
     * Граница - это переход от одного типа эффекта к другому
     * в последовательности statement'ов
     *
     * @param  Node[] $ast
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
     * @param  array<string, EffectGroup> $groups
     * @param  EffectBoundary[]           $boundaries
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
        foreach (array_keys($this->dependencyGraph->getNodes()) as $nodeId) {
            if ($this->dependencyGraph->canReorder($nodeId)) {
                $reorderableNodes[] = $nodeId;
            }
        }

        // Добавляем информацию о переупорядочиваемых узлах в группы
        foreach ($groups as $group) {
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
     * @param  array $pureBlocks Чистые
     *                           блоки из
     *                           PureBlockFinder
     * @return PureComputation[]
     */
    private function extractPureComputations(array $pureBlocks): array
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
     * @param  array<string, EffectGroup> $groups
     * @param  PureComputation[]          $pureComputations
     * @return array<mixed>
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

        $totalPureNodes = array_sum(array_map(fn(\Recombinator\Domain\PureComputation $c): int => $c->size, $pureComputations));

        return [
            'total_nodes' => $totalNodes,
            'total_groups' => count($groups),
            'effect_counts' => $effectCounts,
            'total_pure_computations' => count($pureComputations),
            'total_pure_nodes' => $totalPureNodes,
            'pure_percentage' => $totalNodes > 0 ? round(($totalPureNodes / $totalNodes) * 100, 2) : 0,
            'compile_time_evaluable' => count(array_filter($pureComputations, fn(\Recombinator\Domain\PureComputation $c): bool => $c->isCompileTimeEvaluable)),
        ];
    }
}
