<?php

declare(strict_types=1);

namespace Recombinator\Analysis;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * Калькулятор когнитивной сложности кода
 *
 * Реализует метрику когнитивной сложности согласно docs/rules.md:
 *
 * Критерии начисления баллов:
 * - Каждая бинарная операция: +1 балл
 * - Каждый вызов функции: +2 балла
 * - Каждый уровень вложенности: +1 балл
 * - Каждый доступ к массиву/свойству: +1 балл
 *
 * Классификация сложности:
 * - Простое (0-2 балла): $x + $y, $arr[0], strlen($str)
 * - Среднее (3-5 баллов): $x * $y + $z, $arr[$key]['value'], hash('sha256', $data)
 * - Сложное (6+ баллов): sqrt($x * $x + $y * $y), вложенные вызовы функций
 */
class CognitiveComplexityCalculator
{
    /**
     * Вычисляет когнитивную сложность узла или массива узлов
     *
     * @param Node|Node[] $nodes
     * @return int Сложность в баллах
     */
    public function calculate(Node|array $nodes): int
    {
        if ($nodes instanceof Node) {
            $nodes = [$nodes];
        }

        $visitor = new ComplexityVisitor();
        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);

        foreach ($nodes as $node) {
            $traverser->traverse([$node]);
        }

        return $visitor->getComplexity();
    }

    /**
     * Определяет уровень сложности (простой, средний, сложный)
     */
    public function getComplexityLevel(int $complexity): string
    {
        return match (true) {
            $complexity <= 2 => 'simple',
            $complexity <= 5 => 'medium',
            default => 'complex',
        };
    }
}

/**
 * Visitor для подсчета когнитивной сложности
 */
class ComplexityVisitor extends NodeVisitorAbstract
{
    private int $complexity = 0;
    private int $nestingLevel = 0;

    public function enterNode(Node $node): void
    {
        // Вложенность: +1 балл за каждый уровень
        if ($this->isNestingNode($node)) {
            $this->nestingLevel++;
            $this->complexity += $this->nestingLevel;
        }

        // Бинарные операции: +1 балл
        if ($node instanceof Node\Expr\BinaryOp) {
            $this->complexity++;
        }

        // Вызовы функций: +2 балла
        if ($node instanceof Node\Expr\FuncCall ||
            $node instanceof Node\Expr\MethodCall ||
            $node instanceof Node\Expr\StaticCall) {
            $this->complexity += 2;
        }

        // Доступ к массиву/свойству: +1 балл
        if ($node instanceof Node\Expr\ArrayDimFetch ||
            $node instanceof Node\Expr\PropertyFetch) {
            $this->complexity++;
        }

        // Тернарный оператор: +2 балла (условие + вложенность)
        if ($node instanceof Node\Expr\Ternary) {
            $this->complexity += 2;
        }

        // Логические операторы (&&, ||): +1 балл
        if ($node instanceof Node\Expr\BinaryOp\BooleanAnd ||
            $node instanceof Node\Expr\BinaryOp\BooleanOr ||
            $node instanceof Node\Expr\BinaryOp\LogicalAnd ||
            $node instanceof Node\Expr\BinaryOp\LogicalOr) {
            $this->complexity++;
        }
    }

    public function leaveNode(Node $node): void
    {
        // Выходим из вложенности
        if ($this->isNestingNode($node)) {
            $this->nestingLevel--;
        }
    }

    public function getComplexity(): int
    {
        return $this->complexity;
    }

    /**
     * Проверяет, создает ли узел новый уровень вложенности
     */
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
