<?php

declare(strict_types=1);

namespace Recombinator\Domain;

use PhpParser\Node;

/**
 * Предложение по улучшению структуры кода
 *
 * Содержит информацию о том, как можно улучшить структуру кода:
 * - Извлечение функции
 * - Упрощение условий
 * - Уменьшение вложенности
 * - Разделение сложной логики
 */
class StructureImprovement
{
    /**
     * Типы улучшений
     */
    public const TYPE_EXTRACT_FUNCTION = 'extract_function';

    public const TYPE_SIMPLIFY_CONDITION = 'simplify_condition';

    public const TYPE_REDUCE_NESTING = 'reduce_nesting';

    public const TYPE_SPLIT_LOGIC = 'split_logic';

    public const TYPE_INTRODUCE_VARIABLE = 'introduce_variable';

    public const TYPE_MERGE_OPERATIONS = 'merge_operations';

    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private readonly string $type,
        private readonly string $description,
        private readonly Node $targetNode,
        private array $metadata = []
    ) {
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getTargetNode(): Node
    {
        return $this->targetNode;
    }

    /**
     * @return array<mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getMetadataValue(string $key, mixed $default = null): mixed
    {
        return $this->metadata[$key] ?? $default;
    }

    /**
     * Возвращает ожидаемое улучшение (например, снижение сложности)
     */
    public function getExpectedBenefit(): string
    {
        $complexityReduction = $this->metadata['complexity_reduction'] ?? 'N/A';
        $depthReduction = $this->metadata['depth_reduction'] ?? 'N/A';

        return match ($this->type) {
            self::TYPE_EXTRACT_FUNCTION => 'Reduce complexity by ' . (is_scalar($complexityReduction) ? (string) $complexityReduction : 'N/A'),
            self::TYPE_SIMPLIFY_CONDITION => 'Simplify logic, improve readability',
            self::TYPE_REDUCE_NESTING => 'Reduce nesting depth by ' . (is_scalar($depthReduction) ? (string) $depthReduction : 'N/A'),
            self::TYPE_SPLIT_LOGIC => 'Separate concerns, improve maintainability',
            self::TYPE_INTRODUCE_VARIABLE => 'Improve readability, reduce expression complexity',
            self::TYPE_MERGE_OPERATIONS => 'Reduce redundancy, improve performance',
            default => 'Improve code quality',
        };
    }

    /**
     * Возвращает приоритет применения (0-10)
     */
    public function getPriority(): int
    {
        $priority = $this->metadata['priority'] ?? 5;
        return is_int($priority) ? $priority : (is_numeric($priority) ? (int) $priority : 5);
    }

    /**
     * Форматированное представление для вывода
     */
    public function format(): string
    {
        $line = $this->targetNode->getStartLine();
        $location = 'Line ' . $line;

        return sprintf(
            "[%s] %s\n  %s\n  Expected benefit: %s\n",
            $this->getTypeLabel(),
            $location,
            $this->description,
            $this->getExpectedBenefit()
        );
    }

    private function getTypeLabel(): string
    {
        return match ($this->type) {
            self::TYPE_EXTRACT_FUNCTION => 'Extract Function',
            self::TYPE_SIMPLIFY_CONDITION => 'Simplify Condition',
            self::TYPE_REDUCE_NESTING => 'Reduce Nesting',
            self::TYPE_SPLIT_LOGIC => 'Split Logic',
            self::TYPE_INTRODUCE_VARIABLE => 'Introduce Variable',
            self::TYPE_MERGE_OPERATIONS => 'Merge Operations',
            default => 'Improvement',
        };
    }
}
