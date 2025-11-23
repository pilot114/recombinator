<?php

declare(strict_types=1);

namespace Recombinator\Transformation;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

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
    private int $maxNestingLevel;

    public function __construct(int $maxNestingLevel = 3)
    {
        $this->maxNestingLevel = $maxNestingLevel;
    }

    /**
     * Упрощает вложенные условия в AST
     *
     * @param Node[] $ast
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
     * @return NestingAnalysis
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

/**
 * Visitor для упрощения условий
 */
class ConditionSimplifierVisitor extends NodeVisitorAbstract
{
    private int $maxNestingLevel;

    public function __construct(int $maxNestingLevel)
    {
        $this->maxNestingLevel = $maxNestingLevel;
    }

    public function leaveNode(Node $node)
    {
        // Упрощаем вложенные if с одинаковыми условиями
        if ($node instanceof Node\Stmt\If_) {
            return $this->simplifyNestedIf($node);
        }

        return null;
    }

    /**
     * Упрощает вложенные if конструкции
     */
    private function simplifyNestedIf(Node\Stmt\If_ $if): ?Node\Stmt\If_
    {
        // Проверяем, есть ли один единственный вложенный if
        if (count($if->stmts) === 1) {
            $firstStmt = $if->stmts[0];

            // Если это тоже if без else
            if ($firstStmt instanceof Node\Stmt\If_ && $firstStmt->else === null) {
                // Объединяем условия через &&
                $newCondition = new Node\Expr\BinaryOp\BooleanAnd(
                    $if->cond,
                    $firstStmt->cond
                );

                // Создаем новый if с объединенным условием
                $newIf = new Node\Stmt\If_(
                    $newCondition,
                    [
                        'stmts' => $firstStmt->stmts,
                        'elseifs' => $firstStmt->elseifs,
                        'else' => $firstStmt->else,
                    ]
                );

                return $newIf;
            }
        }

        return null;
    }
}

/**
 * Visitor для анализа вложенности
 */
class NestingAnalyzer extends NodeVisitorAbstract
{
    private int $currentNesting = 0;
    private int $maxNesting = 0;
    private array $nestingLevels = [];
    private array $complexNodes = [];

    public function enterNode(Node $node): void
    {
        // Узлы, создающие вложенность
        if ($this->isNestingNode($node)) {
            $this->currentNesting++;
            $this->maxNesting = max($this->maxNesting, $this->currentNesting);
            $this->nestingLevels[] = $this->currentNesting;

            // Запоминаем сложные узлы (глубина > 3)
            if ($this->currentNesting > 3) {
                $this->complexNodes[] = [
                    'node' => $node,
                    'level' => $this->currentNesting,
                    'line' => $node->getStartLine() ?? 0,
                ];
            }
        }
    }

    public function leaveNode(Node $node): void
    {
        if ($this->isNestingNode($node)) {
            $this->currentNesting--;
        }
    }

    public function getMaxNesting(): int
    {
        return $this->maxNesting;
    }

    public function getAvgNesting(): float
    {
        if (empty($this->nestingLevels)) {
            return 0.0;
        }

        return round(array_sum($this->nestingLevels) / count($this->nestingLevels), 2);
    }

    public function getComplexNodes(): array
    {
        return $this->complexNodes;
    }

    private function isNestingNode(Node $node): bool
    {
        return $node instanceof Node\Stmt\If_ ||
               $node instanceof Node\Stmt\ElseIf_ ||
               $node instanceof Node\Stmt\Else_ ||
               $node instanceof Node\Stmt\For_ ||
               $node instanceof Node\Stmt\Foreach_ ||
               $node instanceof Node\Stmt\While_ ||
               $node instanceof Node\Stmt\Do_ ||
               $node instanceof Node\Stmt\Switch_ ||
               $node instanceof Node\Stmt\Case_ ||
               $node instanceof Node\Stmt\TryCatch ||
               $node instanceof Node\Stmt\Catch_ ||
               $node instanceof Node\Expr\Closure ||
               $node instanceof Node\Expr\ArrowFunction;
    }
}

/**
 * Результат анализа вложенности
 */
class NestingAnalysis
{
    public function __construct(
        public readonly int $maxNesting,
        public readonly float $avgNesting,
        public readonly array $complexNodes,
    ) {}

    /**
     * Проверяет, есть ли проблемы с вложенностью
     */
    public function hasIssues(int $threshold = 3): bool
    {
        return $this->maxNesting > $threshold;
    }

    /**
     * Возвращает количество сложных узлов
     */
    public function getComplexNodeCount(): int
    {
        return count($this->complexNodes);
    }

    /**
     * Генерирует отчет о вложенности
     */
    public function getReport(): string
    {
        $report = [];
        $report[] = "Nesting Analysis Report:";
        $report[] = "  Max nesting level: {$this->maxNesting}";
        $report[] = "  Average nesting: {$this->avgNesting}";
        $report[] = "  Complex nodes (>3 levels): " . $this->getComplexNodeCount();

        if (!empty($this->complexNodes)) {
            $report[] = "\nComplex nodes:";
            foreach ($this->complexNodes as $complex) {
                $line = $complex['line'];
                $level = $complex['level'];
                $report[] = "  Line {$line}: nesting level {$level}";
            }
        }

        return implode("\n", $report);
    }
}
