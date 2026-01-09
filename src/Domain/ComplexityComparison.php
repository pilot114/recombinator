<?php

declare(strict_types=1);

namespace Recombinator\Domain;

/**
 * Класс для сравнения двух наборов метрик
 */
class ComplexityComparison
{
    public function __construct(
        private readonly ComplexityMetrics $before,
        private readonly ComplexityMetrics $after
    ) {
    }

    /**
     * Получает изменение когнитивной сложности
     */
    public function getCognitiveDelta(): int
    {
        return $this->after->getCognitiveComplexity() - $this->before->getCognitiveComplexity();
    }

    /**
     * Получает изменение цикломатической сложности
     */
    public function getCyclomaticDelta(): int
    {
        return $this->after->getCyclomaticComplexity() - $this->before->getCyclomaticComplexity();
    }

    /**
     * Получает процент улучшения когнитивной сложности
     */
    public function getCognitiveImprovement(): float
    {
        if ($this->before->getCognitiveComplexity() === 0) {
            return 0.0;
        }

        $delta = $this->getCognitiveDelta();
        return -($delta / $this->before->getCognitiveComplexity()) * 100;
    }

    /**
     * Получает процент улучшения цикломатической сложности
     */
    public function getCyclomaticImprovement(): float
    {
        if ($this->before->getCyclomaticComplexity() === 0) {
            return 0.0;
        }

        $delta = $this->getCyclomaticDelta();
        return -($delta / $this->before->getCyclomaticComplexity()) * 100;
    }

    /**
     * Проверяет, улучшилась ли сложность
     */
    public function isImproved(): bool
    {
        if ($this->getCognitiveDelta() < 0) {
            return true;
        }

        return $this->getCyclomaticDelta() < 0;
    }

    /**
     * Проверяет, ухудшилась ли сложность
     */
    public function isWorse(): bool
    {
        if ($this->getCognitiveDelta() > 0) {
            return true;
        }

        return $this->getCyclomaticDelta() > 0;
    }

    /**
     * Форматирует сравнение для вывода
     */
    public function format(): string
    {
        $cogDelta = $this->getCognitiveDelta();
        $cycDelta = $this->getCyclomaticDelta();

        $cogSign = $cogDelta > 0 ? '+' : '';
        $cycSign = $cycDelta > 0 ? '+' : '';

        return sprintf(
            "Cognitive: %d → %d (%s%d, %.1f%%), Cyclomatic: %d → %d (%s%d, %.1f%%)",
            $this->before->getCognitiveComplexity(),
            $this->after->getCognitiveComplexity(),
            $cogSign,
            $cogDelta,
            $this->getCognitiveImprovement(),
            $this->before->getCyclomaticComplexity(),
            $this->after->getCyclomaticComplexity(),
            $cycSign,
            $cycDelta,
            $this->getCyclomaticImprovement()
        );
    }
}
