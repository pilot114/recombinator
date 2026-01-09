<?php

namespace Recombinator\Analysis;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

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
        if ($node instanceof Node\Stmt\For_ 
            || $node instanceof Node\Stmt\Foreach_ 
            || $node instanceof Node\Stmt\While_ 
            || $node instanceof Node\Stmt\Do_
        ) {
            $this->complexity++;
        }

        // Switch: каждый case добавляет точку принятия решения
        if ($node instanceof Node\Stmt\Case_ && $node->cond instanceof \PhpParser\Node\Expr) {
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
        if ($node instanceof Node\Expr\BinaryOp\BooleanAnd 
            || $node instanceof Node\Expr\BinaryOp\BooleanOr 
            || $node instanceof Node\Expr\BinaryOp\LogicalAnd 
            || $node instanceof Node\Expr\BinaryOp\LogicalOr
        ) {
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
                fn(\PhpParser\Node\MatchArm $arm): bool => $arm->conds !== null
            );
            $this->complexity += count($nonDefaultArms);
        }
    }

    public function getComplexity(): int
    {
        return $this->complexity;
    }
}
