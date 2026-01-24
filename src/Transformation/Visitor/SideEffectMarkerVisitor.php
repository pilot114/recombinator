<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;
use Recombinator\Analysis\SideEffectClassifier;
use Recombinator\Domain\SideEffectType;

/**
 * Visitor для маркировки узлов AST типами побочных эффектов
 *
 * Проходит по всему дереву AST и помечает каждый узел атрибутом 'side_effect',
 * содержащим тип побочного эффекта (SideEffectType).
 *
 * Использование:
 * ```php
 * $visitor = new SideEffectMarkerVisitor();
 * $traverser = new NodeTraverser();
 * $traverser->addVisitor($visitor);
 * $ast = $traverser->traverse($ast);
 *
 * // Теперь каждый узел имеет атрибут 'side_effect'
 * $effect = $node->getAttribute('side_effect'); // SideEffectType
 * ```
 */
class SideEffectMarkerVisitor extends BaseVisitor
{
    /**
     * Статистика маркировки
     *
     * @var array{
     *     total: int,
     *     pure: int,
     *     io: int,
     *     external_state: int,
     *     global_state: int,
     *     database: int,
     *     http: int,
     *     non_deterministic: int,
     *     mixed: int
     * }
     */
    private array $stats = [
        'total' => 0,
        'pure' => 0,
        'io' => 0,
        'external_state' => 0,
        'global_state' => 0,
        'database' => 0,
        'http' => 0,
        'non_deterministic' => 0,
        'mixed' => 0,
    ];

    public function __construct(private readonly ?SideEffectClassifier $classifier = new SideEffectClassifier())
    {
    }

    /**
     * Вызывается при входе в каждый узел
     *
     * Классифицирует узел и сохраняет тип эффекта в атрибутах
     */
    public function enterNode(Node $node)
    {
        // Классифицируем узел
        $effect = $this->classifier->classify($node);

        // Сохраняем тип эффекта в атрибутах узла
        $node->setAttribute('side_effect', $effect);

        // Обновляем статистику
        $this->updateStats($effect);

        return null;
    }

    /**
     * Обновляет статистику по типам эффектов
     */
    private function updateStats(SideEffectType $effect): void
    {
        $this->stats['total']++;

        match ($effect) {
            SideEffectType::PURE => $this->stats['pure']++,
            SideEffectType::IO => $this->stats['io']++,
            SideEffectType::EXTERNAL_STATE => $this->stats['external_state']++,
            SideEffectType::GLOBAL_STATE => $this->stats['global_state']++,
            SideEffectType::DATABASE => $this->stats['database']++,
            SideEffectType::HTTP => $this->stats['http']++,
            SideEffectType::NON_DETERMINISTIC => $this->stats['non_deterministic']++,
            SideEffectType::MIXED => $this->stats['mixed']++,
        };
    }

    /**
     * Возвращает статистику маркировки
     *
     * @return array{
     *   total: int,
     *   pure: int,
     *   io: int,
     *   external_state: int,
     *   global_state: int,
     *   database: int,
     *   http: int,
     *   non_deterministic: int,
     *   mixed: int
     * }
     */
    public function getStats(): array
    {
        return $this->stats;
    }

    /**
     * Возвращает процентное соотношение типов эффектов
     *
     * @return array<string, float>
     */
    public function getStatsPercentage(): array
    {
        $total = $this->stats['total'];
        if ($total === 0) {
            return [];
        }

        return [
            'pure' => round(($this->stats['pure'] / $total) * 100, 2),
            'io' => round(($this->stats['io'] / $total) * 100, 2),
            'external_state' => round(($this->stats['external_state'] / $total) * 100, 2),
            'global_state' => round(($this->stats['global_state'] / $total) * 100, 2),
            'database' => round(($this->stats['database'] / $total) * 100, 2),
            'http' => round(($this->stats['http'] / $total) * 100, 2),
            'non_deterministic' => round(($this->stats['non_deterministic'] / $total) * 100, 2),
            'mixed' => round(($this->stats['mixed'] / $total) * 100, 2),
        ];
    }

    /**
     * Сбрасывает статистику
     */
    public function resetStats(): void
    {
        $this->stats = [
            'total' => 0,
            'pure' => 0,
            'io' => 0,
            'external_state' => 0,
            'global_state' => 0,
            'database' => 0,
            'http' => 0,
            'non_deterministic' => 0,
            'mixed' => 0,
        ];
    }

    /**
     * Находит все узлы с заданным типом эффекта
     *
     * @param  Node[]         $ast        AST
     *                                    для
     *                                    поиска
     * @param  SideEffectType $effectType Тип эффекта для поиска
     * @return Node[] Узлы с указанным типом эффекта
     */
    public function findNodesByEffect(array $ast, SideEffectType $effectType): array
    {
        $result = [];

        $this->traverseAndCollect($ast, $effectType, $result);

        return $result;
    }

    /**
     * Рекурсивно обходит AST и собирает узлы с заданным типом эффекта
     *
     * @param Node[]|Node    $nodes      Узлы для
     *                                   обхода
     * @param SideEffectType $effectType Тип эффекта для поиска
     * @param Node[]         $result     Результат (передается по ссылке)
     */
    private function traverseAndCollect(array|Node $nodes, SideEffectType $effectType, array &$result): void
    {
        if ($nodes instanceof Node) {
            $nodes = [$nodes];
        }

        foreach ($nodes as $node) {
            if (!$node instanceof Node) {
                continue;
            }

            // Проверяем, помечен ли узел нужным типом эффекта
            $nodeEffect = $node->getAttribute('side_effect');
            if ($nodeEffect === $effectType) {
                $result[] = $node;
            }

            // Рекурсивно обходим дочерние узлы
            foreach ($node->getSubNodeNames() as $name) {
                $subNode = $node->$name;
                if ($subNode instanceof Node) {
                    $this->traverseAndCollect($subNode, $effectType, $result);
                } elseif (is_array($subNode)) {
                    $this->traverseAndCollect($subNode, $effectType, $result);
                }
            }
        }
    }
}
