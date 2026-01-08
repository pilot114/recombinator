<?php

namespace Recombinator\Transformation;

/**
 * Результат анализа вложенности
 */
class NestingAnalysis
{
    public function __construct(
        public readonly int $maxNesting,
        public readonly float $avgNesting,
        public readonly array $complexNodes,
    ) {}

    /**
     * Проверяет, есть ли проблемы с вложенностью
     */
    public function hasIssues(int $threshold = 3): bool
    {
        return $this->maxNesting > $threshold;
    }

    /**
     * Возвращает количество сложных узлов
     */
    public function getComplexNodeCount(): int
    {
        return count($this->complexNodes);
    }

    /**
     * Генерирует отчет о вложенности
     */
    public function getReport(): string
    {
        $report = [];
        $report[] = "Nesting Analysis Report:";
        $report[] = "  Max nesting level: {$this->maxNesting}";
        $report[] = "  Average nesting: {$this->avgNesting}";
        $report[] = "  Complex nodes (>3 levels): " . $this->getComplexNodeCount();

        if (!empty($this->complexNodes)) {
            $report[] = "\nComplex nodes:";
            foreach ($this->complexNodes as $complex) {
                $line = $complex['line'];
                $level = $complex['level'];
                $report[] = "  Line {$line}: nesting level {$level}";
            }
        }

        return implode("\n", $report);
    }
}
