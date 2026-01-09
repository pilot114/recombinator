<?php

declare(strict_types=1);

namespace Recombinator\Transformation;

use PhpParser\Node;
use PhpParser\NodeTraverser;

/**
 * Упрощение вложенных условий
 *
 * Преобразует сложные вложенные if-else конструкции в более простые формы:
 *
 * 1. **Guard clauses**: Инверсия условий с early return
 *    ```php
 *    // До
 *    if ($condition) {
 *        // много кода
 *    } else {
 *        return $error;
 *    }
 *
 *    // После
 *    if (!$condition) {
 *        return $error;
 *    }
 *    // много кода
 *    ```
 *
 * 2. **Объединение условий**: Использование логических операторов
 *    ```php
 *    // До
 *    if ($a) {
 *        if ($b) {
 *            return true;
 *        }
 *    }
 *
 *    // После
 *    if ($a && $b) {
 *        return true;
 *    }
 *    ```
 *
 * 3. **Упрощение вложенных тернарных операторов**
 *    ```php
 *    // До
 *    $result = $a ? ($b ? 'yes' : 'no') : 'maybe';
 *
 *    // После (с промежуточной переменной)
 *    $temp = $b ? 'yes' : 'no';
 *    $result = $a ? $temp : 'maybe';
 *    ```
 */
class NestedConditionSimplifier
{
    public function __construct(private readonly int $maxNestingLevel = 3)
    {
    }

    /**
     * Упрощает вложенные условия в AST
     *
     * @param  Node[] $ast
     * @return Node[]
     */
    public function simplify(array $ast): array
    {
        $visitor = new ConditionSimplifierVisitor($this->maxNestingLevel);
        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);

        return $traverser->traverse($ast);
    }

    /**
     * Анализирует сложность вложенности в AST
     *
     * @param Node[] $ast
     */
    public function analyze(array $ast): NestingAnalysis
    {
        $visitor = new NestingAnalyzer();
        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);

        $traverser->traverse($ast);

        return new NestingAnalysis(
            maxNesting: $visitor->getMaxNesting(),
            avgNesting: $visitor->getAvgNesting(),
            complexNodes: $visitor->getComplexNodes(),
        );
    }
}
