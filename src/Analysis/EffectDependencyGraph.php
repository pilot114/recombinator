<?php

declare(strict_types=1);

namespace Recombinator\Analysis;

use PhpParser\Node;

/**
 * Граф зависимостей побочных эффектов
 *
 * Строит граф зависимостей между узлами AST на основе:
 * 1. Типов побочных эффектов
 * 2. Зависимостей по данным (переменные)
 * 3. Порядка выполнения (control flow)
 *
 * Граф помогает:
 * - Определить, какие узлы можно безопасно переупорядочить
 * - Найти блоки кода, которые можно оптимизировать независимо
 * - Понять влияние побочных эффектов на выполнение программы
 */
class EffectDependencyGraph
{
    /**
     * Узлы графа
     * Каждый элемент: ['node' => Node, 'effect' => SideEffectType, 'id' => string]
     *
     * @var array<string, array{node: Node, effect: SideEffectType, id: string}>
     */
    private array $nodes = [];

    /**
     * Ребра графа (зависимости)
     * Ключ - ID узла, значение - массив ID узлов, от которых он зависит
     *
     * @var array<string, array<string>>
     */
    private array $edges = [];

    /**
     * Обратные ребра (кто зависит от данного узла)
     *
     * @var array<string, array<string>>
     */
    private array $reverseEdges = [];

    /**
     * Строит граф зависимостей из AST
     *
     * AST должен быть предварительно обработан SideEffectMarkerVisitor
     *
     * @param Node[] $ast AST с помеченными узлами
     */
    public function buildFromAST(array $ast): void
    {
        // Сбрасываем состояние
        $this->nodes = [];
        $this->edges = [];
        $this->reverseEdges = [];

        // Собираем все узлы с их эффектами
        $this->collectNodes($ast);

        // Строим зависимости
        $this->buildDependencies();
    }

    /**
     * Рекурсивно собирает узлы из AST
     */
    private function collectNodes(array|Node $nodes, ?string $parentId = null): void
    {
        if ($nodes instanceof Node) {
            $nodes = [$nodes];
        }

        if (!is_array($nodes)) {
            return;
        }

        foreach ($nodes as $node) {
            if (!$node instanceof Node) {
                continue;
            }

            // Получаем тип эффекта из атрибута
            $effect = $node->getAttribute('side_effect');
            if (!$effect) {
                // Если узел не помечен, пропускаем
                continue;
            }

            // Создаем уникальный ID для узла
            $id = $this->createNodeId($node);

            // Добавляем узел в граф
            $this->nodes[$id] = [
                'node' => $node,
                'effect' => $effect,
                'id' => $id,
            ];

            // Если есть родитель, добавляем зависимость
            if ($parentId !== null) {
                $this->addEdge($id, $parentId);
            }

            // Рекурсивно обходим дочерние узлы
            foreach ($node->getSubNodeNames() as $name) {
                $subNode = $node->$name;
                if ($subNode !== null && ($subNode instanceof Node || is_array($subNode))) {
                    $this->collectNodes($subNode, $id);
                }
            }
        }
    }

    /**
     * Создает уникальный ID для узла
     */
    private function createNodeId(Node $node): string
    {
        // Используем позицию в файле как уникальный идентификатор
        return sprintf(
            '%s_%d_%d',
            $node->getType(),
            $node->getStartFilePos() ?? 0,
            $node->getEndFilePos() ?? 0
        );
    }

    /**
     * Добавляет ребро (зависимость) в граф
     *
     * @param string $fromId ID узла, который зависит
     * @param string $toId   ID узла, от
     *                       которого
     *                       зависит
     */
    public function addEdge(string $fromId, string $toId): void
    {
        if (!isset($this->edges[$fromId])) {
            $this->edges[$fromId] = [];
        }

        if (!in_array($toId, $this->edges[$fromId], true)) {
            $this->edges[$fromId][] = $toId;
        }

        // Обновляем обратные ребра
        if (!isset($this->reverseEdges[$toId])) {
            $this->reverseEdges[$toId] = [];
        }

        if (!in_array($fromId, $this->reverseEdges[$toId], true)) {
            $this->reverseEdges[$toId][] = $fromId;
        }
    }

    /**
     * Строит зависимости на основе использования переменных
     */
    private function buildDependencies(): void
    {
        // Отслеживаем присваивания переменных
        $varDefinitions = [];

        foreach ($this->nodes as $id => $nodeData) {
            $node = $nodeData['node'];

            // Если это присваивание, запоминаем определение переменной
            if ($node instanceof Node\Expr\Assign && ($node->var instanceof Node\Expr\Variable && is_string($node->var->name))) {
                $varName = $node->var->name;
                $varDefinitions[$varName] = $id;
            }

            // Если используется переменная, добавляем зависимость от её определения
            $usedVars = $this->findUsedVariables($node);
            foreach ($usedVars as $varName) {
                if (isset($varDefinitions[$varName])) {
                    $this->addEdge($id, $varDefinitions[$varName]);
                }
            }
        }
    }

    /**
     * Находит все используемые переменные в узле
     *
     * @return string[] Имена переменных
     */
    private function findUsedVariables(Node $node): array
    {
        $vars = [];

        // Если это переменная в правой части присваивания или в выражении
        if ($node instanceof Node\Expr\Variable && is_string($node->name)) {
            $vars[] = $node->name;
        }

        // Рекурсивно обходим дочерние узлы
        foreach ($node->getSubNodeNames() as $name) {
            $subNode = $node->$name;

            if ($subNode instanceof Node) {
                $vars = array_merge($vars, $this->findUsedVariables($subNode));
            } elseif (is_array($subNode)) {
                foreach ($subNode as $item) {
                    if ($item instanceof Node) {
                        $vars = array_merge($vars, $this->findUsedVariables($item));
                    }
                }
            }
        }

        return array_unique($vars);
    }

    /**
     * Возвращает зависимости узла
     *
     * @param  string $nodeId ID узла
     * @return string[] ID узлов, от которых зависит данный узел
     */
    public function getDependencies(string $nodeId): array
    {
        return $this->edges[$nodeId] ?? [];
    }

    /**
     * Возвращает узлы, которые зависят от данного узла
     *
     * @param  string $nodeId ID узла
     * @return string[] ID узлов, которые зависят от данного узла
     */
    public function getDependents(string $nodeId): array
    {
        return $this->reverseEdges[$nodeId] ?? [];
    }

    /**
     * Проверяет, может ли узел быть безопасно переупорядочен
     *
     * Узел можно переупорядочить если:
     * 1. Он чистый (PURE)
     * 2. У него нет зависимостей от узлов с побочными эффектами
     *
     * @param string $nodeId ID узла
     */
    public function canReorder(string $nodeId): bool
    {
        if (!isset($this->nodes[$nodeId])) {
            return false;
        }

        $effect = $this->nodes[$nodeId]['effect'];

        // Не чистые узлы нельзя переупорядочивать
        if (!$effect->isPure()) {
            return false;
        }

        // Проверяем зависимости
        $dependencies = $this->getDependencies($nodeId);
        foreach ($dependencies as $depId) {
            if (!isset($this->nodes[$depId])) {
                continue;
            }

            $depEffect = $this->nodes[$depId]['effect'];
            if (!$depEffect->isPure()) {
                // Зависит от узла с побочным эффектом - нельзя переупорядочить
                return false;
            }
        }

        return true;
    }

    /**
     * Возвращает все узлы графа
     *
     * @return array<string, array{node: Node, effect: SideEffectType, id: string}>
     */
    public function getNodes(): array
    {
        return $this->nodes;
    }

    /**
     * Возвращает все ребра графа
     *
     * @return array<string, array<string>>
     */
    public function getEdges(): array
    {
        return $this->edges;
    }

    /**
     * Возвращает узлы, сгруппированные по типу эффекта
     *
     * @return array<string, array<string>>
     */
    public function getNodesByEffect(): array
    {
        $groups = [];

        foreach ($this->nodes as $id => $nodeData) {
            $effectName = $nodeData['effect']->value;

            if (!isset($groups[$effectName])) {
                $groups[$effectName] = [];
            }

            $groups[$effectName][] = $id;
        }

        return $groups;
    }

    /**
     * Возвращает топологическую сортировку узлов
     *
     * Порядок выполнения узлов с учетом зависимостей
     *
     * @return string[] ID узлов в порядке выполнения
     */
    public function topologicalSort(): array
    {
        $sorted = [];
        $visited = [];
        $temp = [];

        foreach (array_keys($this->nodes) as $nodeId) {
            if (!isset($visited[$nodeId])) {
                $this->topologicalSortVisit($nodeId, $visited, $temp, $sorted);
            }
        }

        return array_reverse($sorted);
    }

    /**
     * Рекурсивная функция для топологической сортировки
     */
    private function topologicalSortVisit(
        string $nodeId,
        array &$visited,
        array &$temp,
        array &$sorted
    ): void {
        if (isset($temp[$nodeId])) {
            // Обнаружен цикл - пропускаем
            return;
        }

        if (isset($visited[$nodeId])) {
            return;
        }

        $temp[$nodeId] = true;

        $dependencies = $this->getDependencies($nodeId);
        foreach ($dependencies as $depId) {
            if (isset($this->nodes[$depId])) {
                $this->topologicalSortVisit($depId, $visited, $temp, $sorted);
            }
        }

        unset($temp[$nodeId]);
        $visited[$nodeId] = true;
        $sorted[] = $nodeId;
    }
}
