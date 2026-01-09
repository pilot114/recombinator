<?php

namespace Recombinator\Analysis;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * Visitor для подсчета когнитивной сложности
 */
class ComplexityVisitor extends NodeVisitorAbstract
{
    public int $complexity = 0 {
        get {
            return $this->complexity;
        }
    }

    private int $nestingLevel = 0;

    public function enterNode(Node $node): int|Node|array|null
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
        if ($node instanceof Node\Expr\FuncCall 
            || $node instanceof Node\Expr\MethodCall 
            || $node instanceof Node\Expr\StaticCall
        ) {
            $this->complexity += 2;
        }

        // Доступ к массиву/свойству: +1 балл
        if ($node instanceof Node\Expr\ArrayDimFetch 
            || $node instanceof Node\Expr\PropertyFetch
        ) {
            $this->complexity++;
        }

        // Тернарный оператор: +2 балла (условие + вложенность)
        if ($node instanceof Node\Expr\Ternary) {
            $this->complexity += 2;
        }

        // Логические операторы (&&, ||): +1 балл
        if ($node instanceof Node\Expr\BinaryOp\BooleanAnd 
            || $node instanceof Node\Expr\BinaryOp\BooleanOr 
            || $node instanceof Node\Expr\BinaryOp\LogicalAnd 
            || $node instanceof Node\Expr\BinaryOp\LogicalOr
        ) {
            $this->complexity++;
        }

        return null;
    }

    public function leaveNode(Node $node): int|Node|array|null
    {
        // Выходим из вложенности
        if ($this->isNestingNode($node)) {
            $this->nestingLevel--;
        }

        return null;
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
