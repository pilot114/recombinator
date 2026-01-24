<?php

namespace Recombinator\Transformation;

/**
 * Группа связанных переменных
 */
class RelatedVariableGroup
{
    /**
     * @param array<int, array{var: string, index: int}> $assignments Массив присваиваний
     */
    public function __construct(
        public readonly array $assignments
    ) {
    }

    /**
     * Возвращает количество переменных в группе
     */
    public function getSize(): int
    {
        return count($this->assignments);
    }

    /**
     * Генерирует уникальную подпись группы для дедупликации
     */
    public function getSignature(): string
    {
        $vars = array_map(fn(array $a): string => $a['var'], $this->assignments);
        sort($vars);
        return implode('|', $vars);
    }

    /**
     * Возвращает индексы узлов в группе
     *
     * @return array<int>
     */
    public function getIndices(): array
    {
        return array_map(fn(array $a): int => $a['index'], $this->assignments);
    }
}
