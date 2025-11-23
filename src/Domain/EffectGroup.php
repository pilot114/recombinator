<?php

declare(strict_types=1);

namespace Recombinator\Domain;

use PhpParser\Node;

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
