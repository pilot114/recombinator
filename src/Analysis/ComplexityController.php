<?php

declare(strict_types=1);

namespace Recombinator\Analysis;

use PhpParser\Node;
use Recombinator\Domain\ComplexityComparison;
use Recombinator\Domain\ComplexityMetrics;

/**
 * Контроллер для управления сложностью кода и выдачи предупреждений
 *
 * Отслеживает изменения сложности кода до и после оптимизации,
 * выдает предупреждения о росте сложности и контролирует соблюдение порогов.
 */
class ComplexityController
{
    /**
     * @var ComplexityWarning[]
     */
    private array $warnings = [];

    /**
     * @var array<string, ComplexityMetrics>
     */
    private array $beforeMetrics = [];

    /**
     * @var array<string, ComplexityMetrics>
     */
    private array $afterMetrics = [];

    /**
     * @param integer $cognitiveThreshold  Порог когнитивной сложности (по умолчанию 15)
     * @param integer $cyclomaticThreshold Порог цикломатической сложности (по умолчанию 10)
     * @param boolean $strictMode          Строгий режим - любое увеличение считается ошибкой
     */
    public function __construct(
        private readonly int $cognitiveThreshold = 15,
        private readonly int $cyclomaticThreshold = 10,
        private readonly bool $strictMode = false
    ) {
    }

    /**
     * Регистрирует метрики кода до оптимизации
     *
     * @param string      $identifier Идентификатор (имя функции/метода)
     * @param Node|Node[] $nodes
     */
    public function registerBefore(string $identifier, Node|array $nodes): void
    {
        $this->beforeMetrics[$identifier] = ComplexityMetrics::fromNodes($nodes, $identifier);
    }

    /**
     * Регистрирует метрики кода после оптимизации и проверяет изменения
     *
     * @param string      $identifier Идентификатор (имя функции/метода)
     * @param Node|Node[] $nodes
     */
    public function registerAfter(string $identifier, Node|array $nodes): void
    {
        $this->afterMetrics[$identifier] = ComplexityMetrics::fromNodes($nodes, $identifier);

        // Проверяем изменения и создаем предупреждения
        $this->checkComplexityChanges($identifier);
    }

    /**
     * Проверяет изменения сложности для конкретного идентификатора
     */
    private function checkComplexityChanges(string $identifier): void
    {
        if (!isset($this->beforeMetrics[$identifier]) || !isset($this->afterMetrics[$identifier])) {
            return;
        }

        $before = $this->beforeMetrics[$identifier];
        $after = $this->afterMetrics[$identifier];

        $comparison = $before->compareTo($after);

        // Проверка порогов
        if ($after->getCognitiveComplexity() > $this->cognitiveThreshold) {
            $this->addWarning(
                ComplexityWarning::thresholdExceeded(
                    $identifier,
                    'cognitive',
                    $after->getCognitiveComplexity(),
                    $this->cognitiveThreshold
                )
            );
        }

        if ($after->getCyclomaticComplexity() > $this->cyclomaticThreshold) {
            $this->addWarning(
                ComplexityWarning::thresholdExceeded(
                    $identifier,
                    'cyclomatic',
                    $after->getCyclomaticComplexity(),
                    $this->cyclomaticThreshold
                )
            );
        }

        // Проверка роста сложности
        if ($comparison->getCognitiveDelta() > 0) {
            $this->addWarning(
                ComplexityWarning::increased(
                    $identifier,
                    'cognitive',
                    $before->getCognitiveComplexity(),
                    $after->getCognitiveComplexity(),
                    $this->strictMode ? 'error' : 'warning'
                )
            );
        }

        if ($comparison->getCyclomaticDelta() > 0) {
            $this->addWarning(
                ComplexityWarning::increased(
                    $identifier,
                    'cyclomatic',
                    $before->getCyclomaticComplexity(),
                    $after->getCyclomaticComplexity(),
                    $this->strictMode ? 'error' : 'warning'
                )
            );
        }

        // Информационное сообщение об улучшении
        if ($comparison->isImproved()) {
            $this->addWarning(
                ComplexityWarning::improved(
                    $identifier,
                    $comparison->getCognitiveImprovement(),
                    $comparison->getCyclomaticImprovement()
                )
            );
        }
    }

    /**
     * Добавляет предупреждение
     */
    private function addWarning(ComplexityWarning $warning): void
    {
        $this->warnings[] = $warning;
    }

    /**
     * Получает все предупреждения
     *
     * @return ComplexityWarning[]
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * Получает предупреждения по уровню серьезности
     *
     * @param  string $level 'error', 'warning', 'info'
     * @return ComplexityWarning[]
     */
    public function getWarningsByLevel(string $level): array
    {
        return array_filter(
            $this->warnings,
            fn(ComplexityWarning $w): bool => $w->getLevel() === $level
        );
    }

    /**
     * Проверяет наличие ошибок
     */
    public function hasErrors(): bool
    {
        return $this->getWarningsByLevel('error') !== [];
    }

    /**
     * Проверяет наличие предупреждений
     */
    public function hasWarnings(): bool
    {
        return $this->getWarningsByLevel('warning') !== [];
    }

    /**
     * Получает общую статистику по всем проверкам
     *
     * @return array{total: int, improved: int, worse: int, unchanged: int, errors: int, warnings: int, info: int}
     */
    public function getStatistics(): array
    {
        $totalImproved = 0;
        $totalWorse = 0;
        $totalUnchanged = 0;

        foreach ($this->beforeMetrics as $identifier => $before) {
            if (!isset($this->afterMetrics[$identifier])) {
                continue;
            }

            $after = $this->afterMetrics[$identifier];
            $comparison = $before->compareTo($after);

            if ($comparison->isImproved()) {
                $totalImproved++;
            } elseif ($comparison->isWorse()) {
                $totalWorse++;
            } else {
                $totalUnchanged++;
            }
        }

        return [
            'total' => count($this->beforeMetrics),
            'improved' => $totalImproved,
            'worse' => $totalWorse,
            'unchanged' => $totalUnchanged,
            'errors' => count($this->getWarningsByLevel('error')),
            'warnings' => count($this->getWarningsByLevel('warning')),
            'info' => count($this->getWarningsByLevel('info')),
        ];
    }

    /**
     * Получает сравнение для конкретного идентификатора
     */
    public function getComparison(string $identifier): ?ComplexityComparison
    {
        if (!isset($this->beforeMetrics[$identifier]) || !isset($this->afterMetrics[$identifier])) {
            return null;
        }

        return $this->beforeMetrics[$identifier]->compareTo($this->afterMetrics[$identifier]);
    }

    /**
     * Очищает все данные
     */
    public function clear(): void
    {
        $this->warnings = [];
        $this->beforeMetrics = [];
        $this->afterMetrics = [];
    }

    /**
     * Форматирует отчет для вывода
     */
    public function formatReport(): string
    {
        $stats = $this->getStatistics();
        $lines = [];

        $lines[] = "=== Complexity Analysis Report ===";
        $lines[] = "";
        $lines[] = sprintf("Total functions/methods analyzed: %d", (int) $stats['total']);
        $lines[] = sprintf("  Improved: %d", (int) $stats['improved']);
        $lines[] = sprintf("  Worse: %d", (int) $stats['worse']);
        $lines[] = sprintf("  Unchanged: %d", (int) $stats['unchanged']);
        $lines[] = "";
        $lines[] = sprintf(
            "Issues: %d errors, %d warnings, %d info",
            (int) $stats['errors'],
            (int) $stats['warnings'],
            (int) $stats['info']
        );

        if ($this->warnings !== []) {
            $lines[] = "";
            $lines[] = "=== Warnings ===";

            foreach ($this->warnings as $warning) {
                $lines[] = $warning->format();
            }
        }

        return implode("\n", $lines);
    }
}
