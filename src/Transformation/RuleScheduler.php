<?php

declare(strict_types=1);

namespace Recombinator\Transformation;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use Recombinator\Transformation\Visitor\BaseVisitor;

/**
 * Выбор следующего правила в прогоне до неподвижной точки.
 *
 * Сходимость — каждое правило видело текущий AST и ничего не изменило; изменение кода
 * снова ставит в очередь все правила. Прогон идёт проходами: за проход правило
 * запускается не больше раза, иначе каждое изменение заново запускало бы самые
 * приоритетные правила. Режимы отличаются только выбором из очереди прохода:
 *  - sequential — по порядку VisitorPipeline::main() (прежний конвейер);
 *  - priority   — сначала правило с большим RuleCalibration::$priority (без оценки);
 *  - estimate   — сначала правило с большей эффективностью по BaseVisitor::estimate();
 *                 правило, у которого в AST нет кандидатов, снимается без запуска.
 */
final class RuleScheduler
{
    public const string SEQUENTIAL = 'sequential';

    public const string PRIORITY = 'priority';

    public const string ESTIMATE = 'estimate';

    public const array MODES = [self::ESTIMATE, self::PRIORITY, self::SEQUENTIAL];

    /** Предел запусков одного правила: защита от правил, которые без конца переписывают код друг за другом. */
    public const int MAX_RUNS = 100;

    /** @var array<int, true> правила, ещё не видевшие текущий AST */
    private array $pending;

    /** @var array<int, true> правила, уже запущенные в текущем проходе */
    private array $ran = [];

    /** @var array<int, int> правило → число запусков */
    private array $runs = [];

    /** Номер текущего прохода. */
    public private(set) int $pass = 1;

    /** Правил снято оценкой без запуска. */
    public private(set) int $skipped = 0;

    /** Сводка текущего AST; сбрасывается при изменении кода. */
    private ?AstProfile $profile = null;

    public function __construct(public readonly string $mode, private readonly int $count)
    {
        if (!in_array($mode, self::MODES, true)) {
            throw new \InvalidArgumentException('Unknown schedule mode: ' . $mode);
        }

        $this->pending = array_fill(0, $count, true);
    }

    /**
     * @param  list<NodeVisitorAbstract> $rules правила в порядке VisitorPipeline::main()
     * @param  array<Node>               $ast
     * @return int|null индекс следующего правила; null — сходимость
     */
    public function next(array $rules, array $ast): ?int
    {
        while ($this->pending !== []) {
            $best      = null;
            $bestScore = -INF;
            foreach (array_keys(array_diff_key($this->pending, $this->ran)) as $id) {
                if (($this->runs[$id] ?? 0) >= self::MAX_RUNS) {
                    unset($this->pending[$id]);
                    continue;
                }

                $score = match ($this->mode) {
                    self::SEQUENTIAL => -$id,
                    self::PRIORITY   => RuleCalibration::of($rules[$id]::class)->priority,
                    default          => $this->estimateScore($id, $rules[$id], $ast),
                };

                // При равенстве — раньше в конвейере
                if ($score !== null && $score > $bestScore) {
                    $best      = $id;
                    $bestScore = $score;
                }
            }

            if ($best !== null) {
                return $best;
            }

            // Проход исчерпан, а изменения ставили правила в очередь снова — следующий проход
            if ($this->ran !== []) {
                $this->ran = [];
                $this->pass++;
            }
        }

        return null;
    }

    /** Правило отработало: изменение кода снова ставит в очередь все правила. */
    public function done(int $id, bool $changed): void
    {
        $this->runs[$id] = ($this->runs[$id] ?? 0) + 1;
        $this->ran[$id]  = true;
        unset($this->pending[$id]);
        if ($changed) {
            $this->pending = array_fill(0, $this->count, true);
            $this->profile = null;
        }
    }


    /** Число запусков правил. */
    public function runs(): int
    {
        return array_sum($this->runs);
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        // Сводка AST не сохраняется: после загрузки прогона она строится заново
        return ['mode' => $this->mode, 'count' => $this->count, 'pending' => $this->pending, 'ran' => $this->ran, 'runs' => $this->runs, 'pass' => $this->pass, 'skipped' => $this->skipped];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        foreach ($data as $property => $value) {
            $this->$property = $value;
        }
    }

    /**
     * @param array<Node> $ast
     */
    private function estimateScore(int $id, NodeVisitorAbstract $rule, array $ast): ?float
    {
        $estimate = null;
        if ($rule instanceof BaseVisitor) {
            $this->profile ??= new AstProfile($ast);
            $estimate = $rule->estimate($this->profile);
        }

        if (!$estimate instanceof RuleEstimate) {
            return (float) RuleCalibration::of($rule::class)->priority;
        }

        if ($estimate->candidates === 0) {
            unset($this->pending[$id]);
            $this->skipped++;

            return null;
        }

        return $estimate->efficiency();
    }
}
