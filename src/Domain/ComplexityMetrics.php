<?php

declare(strict_types=1);

namespace Recombinator\Domain;

use PhpParser\Node;
use Recombinator\Analysis\CognitiveComplexityCalculator;
use Recombinator\Analysis\CyclomaticComplexityCalculator;
use Recombinator\Analysis\NestingDepthVisitor;

/**
 * Класс для хранения и сравнения метрик сложности кода
 *
 * Содержит как когнитивную, так и цикломатическую сложность,
 * а также дополнительную информацию для анализа.
 */
class ComplexityMetrics
{
    /**
     * @param integer     $cognitiveComplexity  Когнитивная
     *                                          сложность
     * @param integer     $cyclomaticComplexity Цикломатическая
     *                                          сложность
     * @param integer     $linesOfCode          Количество
     *                                          строк
     *                                          кода
     * @param integer     $nestingDepth         Максимальная
     *                                          глубина
     *                                          вложенности
     * @param string|null $name                 Имя
     *                                          функции/метода
     *                                          (опционально)
     */
    public function __construct(
        private readonly int $cognitiveComplexity,
        private readonly int $cyclomaticComplexity,
        private readonly int $linesOfCode = 0,
        private readonly int $nestingDepth = 0,
        private readonly ?string $name = null
    ) {
    }

    /**
     * Создает метрики из узла AST
     *
     * @param Node|Node[] $nodes
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
     */
    public function compareTo(ComplexityMetrics $other): ComplexityComparison
    {
        return new ComplexityComparison($this, $other);
    }

    /**
     * Проверяет, уменьшилась ли сложность по сравнению с другими метриками
     */
    public function isImprovedComparedTo(ComplexityMetrics $other): bool
    {
        return $this->cognitiveComplexity < $other->cognitiveComplexity ||
               $this->cyclomaticComplexity < $other->cyclomaticComplexity;
    }

    /**
     * Проверяет, ухудшилась ли сложность
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
        $name = $this->name ? $this->name . ': ' : '';
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
     */
    private static function countLines(array $nodes): int
    {
        if ($nodes === []) {
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
