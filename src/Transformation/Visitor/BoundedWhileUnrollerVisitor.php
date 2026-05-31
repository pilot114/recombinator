<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;

/**
 * Разворачивает while-цикл с известным начальным значением счётчика в список
 * отдельных инструкций (только side-effect-less или echo тело):
 *
 *   $n = 5;
 *   while ($n > 0) {
 *       --$n;
 *       if ($n === 3) { continue; }
 *       if ($n === 1) { break; }
 *       echo "tick {$n}" . PHP_EOL;
 *   }
 *   →
 *   echo "tick 4\n";
 *   echo "tick 2\n";
 *
 * Работает только если:
 * - Начальное значение счётчика — скаляр
 * - Тело цикла содержит только: мутации счётчика, if(cond){continue/break}, echo
 * - Цикл завершается менее чем за 500 итераций
 * - Счётчик не читается после цикла
 */
#[VisitorMeta('Разворачивание while-цикла с известным счётчиком')]
class BoundedWhileUnrollerVisitor extends BaseVisitor
{
    #[\Override]
    public function beforeTraverse(array $nodes): ?array
    {
        parent::beforeTraverse($nodes);
        $this->markLoopsForUnrolling($nodes);
        return null;
    }

    private function markLoopsForUnrolling(array $stmts): void
    {
        $count = count($stmts);
        for ($i = 0; $i < $count - 1; $i++) {
            $initStmt  = $stmts[$i];
            $whileStmt = $stmts[$i + 1];

            // $n = scalar
            if (!$initStmt instanceof Node\Stmt\Expression) {
                continue;
            }

            $assign = $initStmt->expr;
            if (!$assign instanceof Node\Expr\Assign) {
                continue;
            }

            if (!$assign->var instanceof Node\Expr\Variable) {
                continue;
            }

            if (!is_string($assign->var->name)) {
                continue;
            }

            $varName = $assign->var->name;
            $initVal = $this->asScalar($assign->expr);
            if ($initVal === null) {
                continue;
            }

            if (!$whileStmt instanceof Node\Stmt\While_) {
                continue;
            }

            // Ensure $varName is not read after the while loop
            $suffix = array_slice($stmts, $i + 2);
            if ($this->varReadInStmts($varName, $suffix)) {
                continue;
            }

            $replacement = $this->tryUnroll($varName, $initVal, $whileStmt);
            if ($replacement === null) {
                continue;
            }

            $initStmt->setAttribute('remove', true);
            $whileStmt->setAttribute('replace', $replacement);
        }
    }

    /**
     * @return array<Node\Stmt>|null  null = can't unroll
     */
    private function tryUnroll(string $varName, int|float|string $initVal, Node\Stmt\While_ $loop): ?array
    {
        $state    = [$varName => $initVal];
        $maxIter  = 500;
        $collected = [];

        while ($maxIter-- > 0) {
            $cond = $this->evalExpr($loop->cond, $state);
            if ($cond === null) {
                return null;
            }

            if (!$cond) {
                break;
            }

            $result = $this->simulateBody($loop->stmts, $state, $collected);
            if ($result === null) {
                return null;
            }

            if ($result === 'break') {
                break;
            }

            // 'normal' or 'continue' → next iteration
        }

        if ($maxIter <= 0) {
            return null;
        }

        return $collected;
    }

    /**
     * @param  array<Node\Stmt>           $bodyStmts
     * @param  array<string, mixed>       $state
     * @param  array<Node\Stmt>           $collected
     * @return 'break'|'continue'|'normal'|null  null = can't simulate
     */
    private function simulateBody(array $bodyStmts, array &$state, array &$collected): ?string
    {
        foreach ($bodyStmts as $stmt) {
            // continue;
            if ($stmt instanceof Node\Stmt\Continue_ && !$stmt->num instanceof \PhpParser\Node\Expr) {
                return 'continue';
            }

            // break;
            if ($stmt instanceof Node\Stmt\Break_ && !$stmt->num instanceof \PhpParser\Node\Expr) {
                return 'break';
            }

            // echo expr1, expr2, ...;
            if ($stmt instanceof Node\Stmt\Echo_) {
                $parts = [];
                foreach ($stmt->exprs as $expr) {
                    $val = $this->evalExpr($expr, $state);
                    if ($val === null) {
                        return null;
                    }

                    $parts[] = (string) $val;
                }

                $collected[] = new Node\Stmt\Echo_([new Node\Scalar\String_(implode('', $parts))]);
                continue;
            }

            // if (cond) { ... }  (no elseif/else)
            if ($stmt instanceof Node\Stmt\If_ && $stmt->elseifs === [] && !$stmt->else instanceof \PhpParser\Node\Stmt\Else_) {
                $cond = $this->evalExpr($stmt->cond, $state);
                if ($cond === null) {
                    return null;
                }

                if ($cond) {
                    $result = $this->simulateBody($stmt->stmts, $state, $collected);
                    if ($result === null) {
                        return null;
                    }

                    if ($result === 'break' || $result === 'continue') {
                        return $result;
                    }
                }

                continue;
            }

            if (!$stmt instanceof Node\Stmt\Expression) {
                return null;
            }

            $expr = $stmt->expr;

            // --$var
            if ($expr instanceof Node\Expr\PreDec && $expr->var instanceof Node\Expr\Variable && is_string($expr->var->name)) {
                $name = $expr->var->name;
                if (!array_key_exists($name, $state)) {
                    return null;
                }

                $state[$name]--;
                continue;
            }

            // $var--
            if ($expr instanceof Node\Expr\PostDec && $expr->var instanceof Node\Expr\Variable && is_string($expr->var->name)) {
                $name = $expr->var->name;
                if (!array_key_exists($name, $state)) {
                    return null;
                }

                $state[$name]--;
                continue;
            }

            // ++$var
            if ($expr instanceof Node\Expr\PreInc && $expr->var instanceof Node\Expr\Variable && is_string($expr->var->name)) {
                $name = $expr->var->name;
                if (!array_key_exists($name, $state)) {
                    return null;
                }

                $state[$name]++;
                continue;
            }

            // $var++
            if ($expr instanceof Node\Expr\PostInc && $expr->var instanceof Node\Expr\Variable && is_string($expr->var->name)) {
                $name = $expr->var->name;
                if (!array_key_exists($name, $state)) {
                    return null;
                }

                $state[$name]++;
                continue;
            }

            // $var = expr
            if ($expr instanceof Node\Expr\Assign && $expr->var instanceof Node\Expr\Variable && is_string($expr->var->name)) {
                $name = $expr->var->name;
                $val  = $this->evalExpr($expr->expr, $state);
                if ($val === null) {
                    return null;
                }

                $state[$name] = $val;
                continue;
            }

            return null;
        }

        return 'normal';
    }

    private function evalExpr(Node\Expr $node, array $state): int|float|string|bool|null
    {
        if ($node instanceof Node\Scalar\LNumber) {
            return $node->value;
        }

        if ($node instanceof Node\Scalar\DNumber) {
            return $node->value;
        }

        if ($node instanceof Node\Scalar\String_) {
            return $node->value;
        }

        // Variable
        if ($node instanceof Node\Expr\Variable && is_string($node->name)) {
            return $state[$node->name] ?? null;
        }

        // Constants (true, false, null, PHP_EOL, …)
        if ($node instanceof Node\Expr\ConstFetch) {
            $name = $node->name->toString();
            return match (strtolower($name)) {
                'true'  => true,
                'false' => false,
                'null'  => null,
                default => defined($name) ? constant($name) : null,
            };
        }

        // "tick {$n}" — interpolated string
        if ($node instanceof Node\Scalar\Encapsed) {
            $result = '';
            foreach ($node->parts as $part) {
                if ($part instanceof Node\InterpolatedStringPart) {
                    $result .= $part->value;
                } elseif ($part instanceof Node\Expr\Variable && is_string($part->name)) {
                    if (!array_key_exists($part->name, $state)) {
                        return null;
                    }

                    $result .= (string) $state[$part->name];
                } else {
                    return null;
                }
            }

            return $result;
        }

        // Concat
        if ($node instanceof Node\Expr\BinaryOp\Concat) {
            $l = $this->evalExpr($node->left, $state);
            $r = $this->evalExpr($node->right, $state);
            return ($l !== null && $r !== null) ? $l . $r : null;
        }

        // Binary comparisons & arithmetic
        if ($node instanceof Node\Expr\BinaryOp) {
            $l = $this->evalExpr($node->left, $state);
            $r = $this->evalExpr($node->right, $state);
            if ($l === null || $r === null) {
                return null;
            }

            return match (true) {
                $node instanceof Node\Expr\BinaryOp\Identical      => $l === $r,
                $node instanceof Node\Expr\BinaryOp\NotIdentical   => $l !== $r,
                $node instanceof Node\Expr\BinaryOp\Equal          => $l == $r,
                $node instanceof Node\Expr\BinaryOp\NotEqual       => $l != $r,
                $node instanceof Node\Expr\BinaryOp\Greater        => $l > $r,
                $node instanceof Node\Expr\BinaryOp\GreaterOrEqual => $l >= $r,
                $node instanceof Node\Expr\BinaryOp\Smaller        => $l < $r,
                $node instanceof Node\Expr\BinaryOp\SmallerOrEqual => $l <= $r,
                $node instanceof Node\Expr\BinaryOp\Plus           => $l + $r,
                $node instanceof Node\Expr\BinaryOp\Minus          => $l - $r,
                $node instanceof Node\Expr\BinaryOp\Mul            => $l * $r,
                $node instanceof Node\Expr\BinaryOp\BooleanAnd     => $l && $r,
                $node instanceof Node\Expr\BinaryOp\BooleanOr      => $l || $r,
                default                                             => null,
            };
        }

        // Unary
        if ($node instanceof Node\Expr\UnaryMinus) {
            $v = $this->evalExpr($node->expr, $state);
            return $v !== null ? -$v : null;
        }

        if ($node instanceof Node\Expr\BooleanNot) {
            $v = $this->evalExpr($node->expr, $state);
            return $v !== null ? !$v : null;
        }

        return null;
    }

    private function asScalar(Node\Expr $node): int|float|string|null
    {
        if ($node instanceof Node\Scalar\LNumber) {
            return $node->value;
        }

        if ($node instanceof Node\Scalar\DNumber) {
            return $node->value;
        }

        if ($node instanceof Node\Scalar\String_) {
            return $node->value;
        }

        return null;
    }

    private function varReadInStmts(string $varName, array $stmts): bool
    {
        return array_any($this->findNode(Node\Expr\Variable::class, $stmts), fn($var): bool => $var->name === $varName);
    }
}
