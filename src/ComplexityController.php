<?php

declare(strict_types=1);

namespace Recombinator;

use PhpParser\Node;
use Recombinator\ComplexityComparison;

/**
 * Контроллер для управления сложностью кода и выдачи предупреждений
 *
 * Отслеживает изменения сложности кода до и после оптимизации,
 * выдает предупреждения о росте сложности и контролирует соблюдение порогов.
 */
class ComplexityController
{
    /** @var ComplexityWarning[] */
    private array $warnings = [];

    /** @var array<string, ComplexityMetrics> */
    private array $beforeMetrics = [];

    /** @var array<string, ComplexityMetrics> */
    private array $afterMetrics = [];

    /**
     * @param int $cognitiveThreshold Порог когнитивной сложности (по умолчанию 15)
     * @param int $cyclomaticThreshold Порог цикломатической сложности (по умолчанию 10)
     * @param bool $strictMode Строгий режим - любое увеличение считается ошибкой
     */
    public function __construct(
        private int $cognitiveThreshold = 15,
        private int $cyclomaticThreshold = 10,
        private bool $strictMode = false
    ) {
    }

    /**
     * Регистрирует метрики кода до оптимизации
     *
     * @param string $identifier Идентификатор (имя функции/метода)
     * @param Node|Node[] $nodes
     */
    public function registerBefore(string $identifier, Node|array $nodes): void
    {
        $this->beforeMetrics[$identifier] = ComplexityMetrics::fromNodes($nodes, $identifier);
    }

    /**
     * Регистрирует метрики кода после оптимизации и проверяет изменения
     *
     * @param string $identifier Идентификатор (имя функции/метода)
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
     * @param string $level 'error', 'warning', 'info'
     * @return ComplexityWarning[]
     */
    public function getWarningsByLevel(string $level): array
    {
        return array_filter(
            $this->warnings,
            fn(ComplexityWarning $w) => $w->getLevel() === $level
        );
    }

    /**
     * Проверяет наличие ошибок
     */
    public function hasErrors(): bool
    {
        return !empty($this->getWarningsByLevel('error'));
    }

    /**
     * Проверяет наличие предупреждений
     */
    public function hasWarnings(): bool
    {
        return !empty($this->getWarningsByLevel('warning'));
    }

    /**
     * Получает общую статистику по всем проверкам
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
        $lines[] = sprintf("Total functions/methods analyzed: %d", $stats['total']);
        $lines[] = sprintf("  Improved: %d", $stats['improved']);
        $lines[] = sprintf("  Worse: %d", $stats['worse']);
        $lines[] = sprintf("  Unchanged: %d", $stats['unchanged']);
        $lines[] = "";
        $lines[] = sprintf("Issues: %d errors, %d warnings, %d info",
            $stats['errors'], $stats['warnings'], $stats['info']);

        if (!empty($this->warnings)) {
            $lines[] = "";
            $lines[] = "=== Warnings ===";

            foreach ($this->warnings as $warning) {
                $lines[] = $warning->format();
            }
        }

        return implode("\n", $lines);
    }
}

/**
 * Класс для представления предупреждения о сложности
 */
class ComplexityWarning
{
    /**
     * @param string $identifier Идентификатор функции/метода
     * @param string $type Тип предупреждения
     * @param string $level Уровень: 'error', 'warning', 'info'
     * @param string $message Сообщение
     * @param array<string, mixed> $context Контекст (значения метрик и т.д.)
     */
    public function __construct(
        private string $identifier,
        private string $type,
        private string $level,
        private string $message,
        private array $context = []
    ) {
    }

    /**
     * Создает предупреждение о превышении порога
     */
    public static function thresholdExceeded(
        string $identifier,
        string $metric,
        int $value,
        int $threshold
    ): self {
        return new self(
            identifier: $identifier,
            type: 'threshold_exceeded',
            level: 'warning',
            message: sprintf(
                "%s complexity (%d) exceeds threshold (%d)",
                ucfirst($metric),
                $value,
                $threshold
            ),
            context: [
                'metric' => $metric,
                'value' => $value,
                'threshold' => $threshold,
            ]
        );
    }

    /**
     * Создает предупреждение о росте сложности
     */
    public static function increased(
        string $identifier,
        string $metric,
        int $before,
        int $after,
        string $level = 'warning'
    ): self {
        return new self(
            identifier: $identifier,
            type: 'complexity_increased',
            level: $level,
            message: sprintf(
                "%s complexity increased from %d to %d (+%d)",
                ucfirst($metric),
                $before,
                $after,
                $after - $before
            ),
            context: [
                'metric' => $metric,
                'before' => $before,
                'after' => $after,
                'delta' => $after - $before,
            ]
        );
    }

    /**
     * Создает информационное сообщение об улучшении
     */
    public static function improved(
        string $identifier,
        float $cognitiveImprovement,
        float $cyclomaticImprovement
    ): self {
        return new self(
            identifier: $identifier,
            type: 'complexity_improved',
            level: 'info',
            message: sprintf(
                "Complexity improved: cognitive %.1f%%, cyclomatic %.1f%%",
                $cognitiveImprovement,
                $cyclomaticImprovement
            ),
            context: [
                'cognitive_improvement' => $cognitiveImprovement,
                'cyclomatic_improvement' => $cyclomaticImprovement,
            ]
        );
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getLevel(): string
    {
        return $this->level;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Форматирует предупреждение для вывода
     */
    public function format(): string
    {
        $levelPrefix = match ($this->level) {
            'error' => '[ERROR]',
            'warning' => '[WARN]',
            'info' => '[INFO]',
            default => '[' . strtoupper($this->level) . ']',
        };

        return sprintf("%s %s: %s", $levelPrefix, $this->identifier, $this->message);
    }
}
