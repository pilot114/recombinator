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
    public function __construct(
        private readonly ?NamingSuggester $namingSuggester = new NamingSuggester(),
        private readonly ?CognitiveComplexityCalculator $complexityCalculator = new CognitiveComplexityCalculator(),
        /**
         * Порог когнитивной сложности для выражения
         */
        private readonly int $complexityThreshold = 5,
        /**
         * Максимальная допустимая глубина вложенности
         */
        private readonly int $maxNestingDepth = 3,
        /**
         * Минимальная оценка качества имени (0-10)
         */
        private readonly int $minNameQuality = 5
    ) {
    }

    /**
     * Анализирует AST и находит кандидатов на интерактивную правку
     *
     * @param Node[] $ast
     */
    public function analyze(array $ast): InteractiveEditResult
    {
        $visitor = new InteractiveEditVisitor(
            $this->namingSuggester ?? new NamingSuggester(),
            $this->complexityCalculator ?? new CognitiveComplexityCalculator(),
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
