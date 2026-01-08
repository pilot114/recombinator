<?php

namespace Recombinator\Transformation;

/**
 * Группа связанных переменных
 */
class RelatedVariableGroup
{
    /**
     * @param array $assignments Массив присваиваний
     */
    public function __construct(
        public readonly array $assignments
    ) {}

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
        $vars = array_map(fn($a) => $a['var'], $this->assignments);
        sort($vars);
        return implode('|', $vars);
    }

    /**
     * Возвращает индексы узлов в группе
     *
     * @return int[]
     */
    public function getIndices(): array
    {
        return array_map(fn($a) => $a['index'], $this->assignments);
    }
}
