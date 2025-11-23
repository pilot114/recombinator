<?php

declare(strict_types=1);

namespace Recombinator\Analysis;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * Калькулятор цикломатической сложности кода (Cyclomatic Complexity)
 *
 * Реализует метрику Томаса Маккейба (Thomas McCabe, 1976).
 *
 * Цикломатическая сложность = 1 + количество точек принятия решений
 *
 * Точки принятия решений:
 * - if, elseif, else
 * - for, foreach, while, do-while
 * - case в switch
 * - catch в try-catch
 * - тернарный оператор ?:
 * - логические операторы && и ||
 * - оператор ?? (null coalescing)
 *
 * Интерпретация результатов:
 * - 1-10: Простой код, легко тестируемый
 * - 11-20: Умеренно сложный
 * - 21-50: Сложный, трудно тестируемый
 * - 51+: Очень сложный, непригодный для тестирования
 */
class CyclomaticComplexityCalculator
{
    /**
     * Вычисляет цикломатическую сложность узла или массива узлов
     *
     * @param Node|Node[] $nodes
     * @return int Цикломатическая сложность
     */
    public function calculate(Node|array $nodes): int
    {
        if ($nodes instanceof Node) {
            $nodes = [$nodes];
        }

        $visitor = new CyclomaticComplexityVisitor();
        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);

        foreach ($nodes as $node) {
            $traverser->traverse([$node]);
        }

        return $visitor->getComplexity();
    }

    /**
     * Определяет уровень сложности по цикломатической метрике
     *
     * @param int $complexity Цикломатическая сложность
     * @return string Уровень сложности: 'simple', 'moderate', 'complex', 'very_complex'
     */
    public function getComplexityLevel(int $complexity): string
    {
        return match (true) {
            $complexity <= 10 => 'simple',
            $complexity <= 20 => 'moderate',
            $complexity <= 50 => 'complex',
            default => 'very_complex',
        };
    }

    /**
     * Проверяет, является ли сложность приемлемой
     *
     * @param int $complexity Цикломатическая сложность
     * @param int $threshold Порог (по умолчанию 10)
     * @return bool
     */
    public function isAcceptable(int $complexity, int $threshold = 10): bool
    {
        return $complexity <= $threshold;
    }

    /**
     * Вычисляет среднюю цикломатическую сложность для набора функций/методов
     *
     * @param array<Node\Stmt\Function_|Node\Stmt\ClassMethod> $functions
     * @return float
     */
    public function calculateAverage(array $functions): float
    {
        if (empty($functions)) {
            return 0.0;
        }

        $total = 0;
        foreach ($functions as $function) {
            $total += $this->calculate($function);
        }

        return $total / count($functions);
    }
}

/**
 * Visitor для подсчета цикломатической сложности
 */
class CyclomaticComplexityVisitor extends NodeVisitorAbstract
{
    private int $complexity = 1; // Базовая сложность = 1

    public function enterNode(Node $node): void
    {
        // If, ElseIf, Else
        if ($node instanceof Node\Stmt\If_) {
            $this->complexity++; // За сам if
            // ElseIf обрабатывается отдельно
        }

        if ($node instanceof Node\Stmt\ElseIf_) {
            $this->complexity++;
        }

        // Циклы
        if ($node instanceof Node\Stmt\For_ ||
            $node instanceof Node\Stmt\Foreach_ ||
            $node instanceof Node\Stmt\While_ ||
            $node instanceof Node\Stmt\Do_) {
            $this->complexity++;
        }

        // Switch: каждый case добавляет точку принятия решения
        if ($node instanceof Node\Stmt\Case_ && $node->cond !== null) {
            $this->complexity++;
        }

        // Try-Catch: каждый catch добавляет точку принятия решения
        if ($node instanceof Node\Stmt\Catch_) {
            $this->complexity++;
        }

        // Тернарный оператор
        if ($node instanceof Node\Expr\Ternary) {
            $this->complexity++;
        }

        // Логические операторы && и ||
        if ($node instanceof Node\Expr\BinaryOp\BooleanAnd ||
            $node instanceof Node\Expr\BinaryOp\BooleanOr ||
            $node instanceof Node\Expr\BinaryOp\LogicalAnd ||
            $node instanceof Node\Expr\BinaryOp\LogicalOr) {
            $this->complexity++;
        }

        // Null coalescing operator ??
        if ($node instanceof Node\Expr\BinaryOp\Coalesce) {
            $this->complexity++;
        }

        // Match expression (PHP 8.0+): каждая ветка добавляет сложность
        if ($node instanceof Node\Expr\Match_) {
            // Match сам по себе = 1, плюс каждая arm (кроме default)
            $nonDefaultArms = array_filter(
                $node->arms,
                fn($arm) => $arm->conds !== null
            );
            $this->complexity += count($nonDefaultArms);
        }
    }

    public function getComplexity(): int
    {
        return $this->complexity;
    }
}
