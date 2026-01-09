<?php

namespace Recombinator\Analysis;

/**
 * Класс для представления предупреждения о сложности
 */
class ComplexityWarning
{
    /**
     * @param string               $identifier Идентификатор
     *                                         функции/метода
     * @param string               $type       Тип
     *                                         предупреждения
     * @param string               $level      Уровень: 'error',
     *                                         'warning', 'info'
     * @param string               $message    Сообщение
     * @param array<string, mixed> $context    Контекст
     *                                         (значения метрик и
     *                                         т.д.)
     */
    public function __construct(
        private readonly string $identifier,
        private readonly string $type,
        private readonly string $level,
        private readonly string $message,
        private readonly array $context = []
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
