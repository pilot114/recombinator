<?php

declare(strict_types=1);

namespace Recombinator\Transformation;

/**
 * Быстрая оценка применения правила к текущему AST (BaseVisitor::estimate).
 */
final readonly class RuleEstimate
{
    /**
     * @param int   $candidates узлов, на которые правило может подействовать; 0 — правило заведомо ничего не изменит
     * @param float $nodes      ожидаемое число убранных узлов (отрицательное — правило добавит узлы, например встроит код)
     * @param float $timeMs     ожидаемое время прохода правила
     */
    public function __construct(
        public int $candidates,
        public float $nodes,
        public float $timeMs,
    ) {
    }

    /** Эффективность: убранных узлов в секунду — в тех же единицах, что RuleCalibration::$priority. */
    public function efficiency(): float
    {
        return $this->nodes / max($this->timeMs, 0.001) * 1000;
    }
}
