<?php

declare(strict_types=1);

namespace Recombinator;

use PhpParser\Node;

/**
 * Класс для хранения и сравнения метрик сложности кода
 *
 * Содержит как когнитивную, так и цикломатическую сложность,
 * а также дополнительную информацию для анализа.
 */
class ComplexityMetrics
{
    /**
     * @param int $cognitiveComplexity Когнитивная сложность
     * @param int $cyclomaticComplexity Цикломатическая сложность
     * @param int $linesOfCode Количество строк кода
     * @param int $nestingDepth Максимальная глубина вложенности
     * @param string|null $name Имя функции/метода (опционально)
     */
    public function __construct(
        private int $cognitiveComplexity,
        private int $cyclomaticComplexity,
        private int $linesOfCode = 0,
        private int $nestingDepth = 0,
        private ?string $name = null
    ) {
    }

    /**
     * Создает метрики из узла AST
     *
     * @param Node|Node[] $nodes
     * @param string|null $name
     * @return self
     */
    public static function fromNodes(Node|array $nodes, ?string $name = null): self
    {
        $cognitiveCalc = new CognitiveComplexityCalculator();
        $cyclomaticCalc = new CyclomaticComplexityCalculator();

        $nodeArray = is_array($nodes) ? $nodes : [$nodes];

        return new self(
            cognitiveComplexity: $cognitiveCalc->calculate($nodeArray),
            cyclomaticComplexity: $cyclomaticCalc->calculate($nodeArray),
            linesOfCode: self::countLines($nodeArray),
            nestingDepth: self::calculateNestingDepth($nodeArray),
            name: $name
        );
    }

    public function getCognitiveComplexity(): int
    {
        return $this->cognitiveComplexity;
    }

    public function getCyclomaticComplexity(): int
    {
        return $this->cyclomaticComplexity;
    }

    public function getLinesOfCode(): int
    {
        return $this->linesOfCode;
    }

    public function getNestingDepth(): int
    {
        return $this->nestingDepth;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    /**
     * Сравнивает с другими метриками
     *
     * @param ComplexityMetrics $other
     * @return ComplexityComparison
     */
    public function compareTo(ComplexityMetrics $other): ComplexityComparison
    {
        return new ComplexityComparison($this, $other);
    }

    /**
     * Проверяет, уменьшилась ли сложность по сравнению с другими метриками
     *
     * @param ComplexityMetrics $other
     * @return bool
     */
    public function isImprovedComparedTo(ComplexityMetrics $other): bool
    {
        return $this->cognitiveComplexity < $other->cognitiveComplexity ||
               $this->cyclomaticComplexity < $other->cyclomaticComplexity;
    }

    /**
     * Проверяет, ухудшилась ли сложность
     *
     * @param ComplexityMetrics $other
     * @return bool
     */
    public function isWorseComparedTo(ComplexityMetrics $other): bool
    {
        return $this->cognitiveComplexity > $other->cognitiveComplexity ||
               $this->cyclomaticComplexity > $other->cyclomaticComplexity;
    }

    /**
     * Получает общую оценку сложности (комбинированная метрика)
     *
     * Использует взвешенную сумму обеих метрик
     */
    public function getOverallComplexity(): float
    {
        // Когнитивная сложность имеет больший вес (70%), т.к. лучше отражает читаемость
        return $this->cognitiveComplexity * 0.7 + $this->cyclomaticComplexity * 0.3;
    }

    /**
     * Возвращает уровень когнитивной сложности
     */
    public function getCognitiveComplexityLevel(): string
    {
        $calc = new CognitiveComplexityCalculator();
        return $calc->getComplexityLevel($this->cognitiveComplexity);
    }

    /**
     * Возвращает уровень цикломатической сложности
     */
    public function getCyclomaticComplexityLevel(): string
    {
        $calc = new CyclomaticComplexityCalculator();
        return $calc->getComplexityLevel($this->cyclomaticComplexity);
    }

    /**
     * Форматирует метрики для вывода
     */
    public function format(): string
    {
        $name = $this->name ? "{$this->name}: " : '';
        return sprintf(
            '%sCognitive: %d (%s), Cyclomatic: %d (%s), LOC: %d, Nesting: %d',
            $name,
            $this->cognitiveComplexity,
            $this->getCognitiveComplexityLevel(),
            $this->cyclomaticComplexity,
            $this->getCyclomaticComplexityLevel(),
            $this->linesOfCode,
            $this->nestingDepth
        );
    }

    /**
     * Подсчитывает количество строк кода
     *
     * @param Node[] $nodes
     * @return int
     */
    private static function countLines(array $nodes): int
    {
        if (empty($nodes)) {
            return 0;
        }

        $minLine = PHP_INT_MAX;
        $maxLine = 0;

        foreach ($nodes as $node) {
            $startLine = $node->getStartLine();
            $endLine = $node->getEndLine();

            if ($startLine !== -1) {
                $minLine = min($minLine, $startLine);
            }
            if ($endLine !== -1) {
                $maxLine = max($maxLine, $endLine);
            }
        }

        return $maxLine - $minLine + 1;
    }

    /**
     * Вычисляет максимальную глубину вложенности
     *
     * @param Node[] $nodes
     * @return int
     */
    private static function calculateNestingDepth(array $nodes): int
    {
        $visitor = new NestingDepthVisitor();
        $traverser = new \PhpParser\NodeTraverser();
        $traverser->addVisitor($visitor);

        foreach ($nodes as $node) {
            $traverser->traverse([$node]);
        }

        return $visitor->getMaxDepth();
    }
}
