<?php

declare(strict_types=1);

namespace Recombinator\Interactive;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use Recombinator\Analysis\CognitiveComplexityCalculator;
use Recombinator\Domain\NamingSuggester;

/**
 * Анализатор кода для интерактивных правок (Phase 5.1)
 *
 * Анализирует код и находит места, требующие ручной правки:
 * - Плохие имена переменных и функций
 * - Сложные выражения
 * - Магические числа и строки
 * - Глубокая вложенность
 * - Возможности для улучшения структуры
 *
 * Пример использования:
 * ```php
 * $analyzer = new InteractiveEditAnalyzer();
 * $result = $analyzer->analyze($ast);
 *
 * foreach ($result->getEditCandidates() as $candidate) {
 *     echo $candidate->format();
 * }
 *
 * foreach ($result->getStructureImprovements() as $improvement) {
 *     echo $improvement->format();
 * }
 * ```
 */
class InteractiveEditAnalyzer
{
    private NamingSuggester $namingSuggester;
    private CognitiveComplexityCalculator $complexityCalculator;

    /**
     * Порог когнитивной сложности для выражения
     */
    private int $complexityThreshold;

    /**
     * Максимальная допустимая глубина вложенности
     */
    private int $maxNestingDepth;

    /**
     * Минимальная оценка качества имени (0-10)
     */
    private int $minNameQuality;

    public function __construct(
        ?NamingSuggester $namingSuggester = null,
        ?CognitiveComplexityCalculator $complexityCalculator = null,
        int $complexityThreshold = 5,
        int $maxNestingDepth = 3,
        int $minNameQuality = 5
    ) {
        $this->namingSuggester = $namingSuggester ?? new NamingSuggester();
        $this->complexityCalculator = $complexityCalculator ?? new CognitiveComplexityCalculator();
        $this->complexityThreshold = $complexityThreshold;
        $this->maxNestingDepth = $maxNestingDepth;
        $this->minNameQuality = $minNameQuality;
    }

    /**
     * Анализирует AST и находит кандидатов на интерактивную правку
     *
     * @param Node[] $ast
     * @return InteractiveEditResult
     */
    public function analyze(array $ast): InteractiveEditResult
    {
        $visitor = new InteractiveEditVisitor(
            $this->namingSuggester,
            $this->complexityCalculator,
            $this->complexityThreshold,
            $this->maxNestingDepth,
            $this->minNameQuality
        );

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        return $visitor->getResult();
    }
}
