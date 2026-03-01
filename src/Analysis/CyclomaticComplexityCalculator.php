<?php

declare(strict_types=1);

namespace Recombinator\Analysis;

use PhpParser\Node;
use PhpParser\NodeTraverser;

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
class CyclomaticComplexityCalculator implements \Recombinator\Contract\ComplexityCalculatorInterface
{
    /**
     * Вычисляет цикломатическую сложность узла или массива узлов
     *
     * @param  Node|Node[] $nodes
     * @return integer Цикломатическая сложность
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
     * @param  integer $complexity Цикломатическая сложность
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
     * @param integer $complexity Цикломатическая сложность
     * @param integer $threshold  Порог (по
     *                            умолчанию 10)
     */
    public function isAcceptable(int $complexity, int $threshold = 10): bool
    {
        return $complexity <= $threshold;
    }

    /**
     * Вычисляет среднюю цикломатическую сложность для набора функций/методов
     *
     * @param array<Node\Stmt\Function_|Node\Stmt\ClassMethod> $functions
     */
    public function calculateAverage(array $functions): float
    {
        if ($functions === []) {
            return 0.0;
        }

        $total = 0;
        foreach ($functions as $function) {
            $total += $this->calculate($function);
        }

        return $total / count($functions);
    }
}
