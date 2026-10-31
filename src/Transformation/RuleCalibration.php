<?php

declare(strict_types=1);

namespace Recombinator\Transformation;

/**
 * Калибровка правила по сравнительным прогонам (bin/rule-stats.php --write → rule-calibration.php).
 */
final readonly class RuleCalibration
{
    /** Время на узел для правила без калибровки: порядок медианы по прогонам примеров. */
    public const float DEFAULT_MS_PER_NODE = 0.002;

    /**
     * @param int   $priority  приоритет по умолчанию — убранных узлов в секунду в среднем по всем
     *                         запускам правила; чем выше, тем раньше правило в прогоне без оценки
     * @param float $msPerNode время прохода на узел AST
     * @param float $yield     доля узлов поддеревьев-кандидатов, которую правило в среднем убирает
     */
    public function __construct(
        public int $priority = 0,
        public float $msPerNode = self::DEFAULT_MS_PER_NODE,
        public float $yield = 0.0,
    ) {
    }

    public static function of(string $rule): self
    {
        /** @var array<string, array{priority: int, msPerNode: float, yield: float}> $data */
        static $data = require __DIR__ . '/rule-calibration.php';

        return isset($data[$rule]) ? new self($data[$rule]['priority'], $data[$rule]['msPerNode'], $data[$rule]['yield']) : new self();
    }
}
