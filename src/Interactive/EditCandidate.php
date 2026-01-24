<?php

declare(strict_types=1);

namespace Recombinator\Interactive;

use PhpParser\Node;

/**
 * Кандидат на интерактивное редактирование
 *
 * Представляет узел AST, который требует ручной правки пользователем.
 * Содержит информацию о проблеме и предложения по улучшению.
 */
class EditCandidate
{
    /**
     * Типы проблем
     */
    public const ISSUE_POOR_NAMING = 'poor_naming';

               // Плохое именование
    public const ISSUE_COMPLEX_EXPRESSION = 'complex_expr';

       // Сложное выражение
    public const ISSUE_MAGIC_NUMBER = 'magic_number';

             // Магическое число
    public const ISSUE_LONG_LINE = 'long_line';

                   // Длинная строка
    public const ISSUE_DEEP_NESTING = 'deep_nesting';

             // Глубокая вложенность
    public const ISSUE_DUPLICATE_CODE = 'duplicate_code';     // Дублирование кода

    /**
     * Приоритеты исправления
     */
    public const PRIORITY_CRITICAL = 3;

     // Критично (влияет на понимание кода)
    public const PRIORITY_HIGH = 2;

         // Высокий (значительно улучшит код)
    public const PRIORITY_MEDIUM = 1;

       // Средний (желательно исправить)
    public const PRIORITY_LOW = 0;      // Низкий (опционально)

    /**
     * @param array<string> $suggestions
     */
    public function __construct(
        private readonly Node $node,
        private readonly string $issueType,
        private readonly string $description,
        private readonly int $priority,
        private array $suggestions = []
    ) {
    }

    public function getNode(): Node
    {
        return $this->node;
    }

    public function getIssueType(): string
    {
        return $this->issueType;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function getPriorityLabel(): string
    {
        return match ($this->priority) {
            self::PRIORITY_CRITICAL => 'CRITICAL',
            self::PRIORITY_HIGH => 'HIGH',
            self::PRIORITY_MEDIUM => 'MEDIUM',
            self::PRIORITY_LOW => 'LOW',
            default => 'UNKNOWN',
        };
    }

    /**
     * @return array<string>
     */
    public function getSuggestions(): array
    {
        return $this->suggestions;
    }

    public function addSuggestion(string $suggestion): void
    {
        $this->suggestions[] = $suggestion;
    }

    /**
     * Возвращает номер строки узла в исходном коде
     */
    public function getLine(): ?int
    {
        return $this->node->getStartLine();
    }

    /**
     * Проверяет, является ли кандидат критичным
     */
    public function isCritical(): bool
    {
        return $this->priority === self::PRIORITY_CRITICAL;
    }

    /**
     * Форматированное представление для вывода
     */
    public function format(): string
    {
        $line = $this->getLine();
        $location = ($line !== null && $line !== -1) ? 'Line ' . $line : "Unknown location";

        $output = sprintf(
            "[%s] %s: %s\n",
            $this->getPriorityLabel(),
            $location,
            $this->description
        );

        if ($this->suggestions !== []) {
            $output .= "Suggestions:\n";
            $index = 1;
            foreach ($this->suggestions as $suggestion) {
                $output .= sprintf("  %d) %s\n", $index, (string) $suggestion);
                $index++;
            }
        }

        return $output;
    }
}
