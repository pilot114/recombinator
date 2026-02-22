<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;

/**
 * Свёртка константных выражений на этапе компиляции:
 *
 * 1. Сравнения скаляров → true/false
 *    'test' === 'test'  →  true
 *    1 !== 2            →  true
 *    3 > 5              →  false
 *
 * 2. Тернарный оператор с константным условием → соответствующая ветка
 *    true  ? 'a' : 'b'  →  'a'
 *    false ? 'a' : 'b'  →  'b'
 *
 * Работает снизу вверх (leaveNode), поэтому вложенные выражения
 * сворачиваются раньше внешних.
 */
#[VisitorMeta('Свёртка константных сравнений и тернарных операций')]
class ConstFoldVisitor extends BaseVisitor
{
    #[\Override]
    public function leaveNode(Node $node): int|Node|array|null
    {
        if ($node instanceof Node\Expr\BinaryOp) {
            $folded = $this->foldComparison($node);
            if ($folded instanceof \PhpParser\Node) {
                return $folded;
            }
        }

        if ($node instanceof Node\Expr\Ternary) {
            $folded = $this->foldTernary($node);
            if ($folded instanceof \PhpParser\Node) {
                return $folded;
            }
        }

        return parent::leaveNode($node);
    }

    // ── Comparison folding ───────────────────────────────────────────────────

    private function foldComparison(Node\Expr\BinaryOp $node): ?Node
    {
        $l = $this->asScalar($node->left);
        $r = $this->asScalar($node->right);
        if ($l === null || $r === null) {
            return null;
        }

        [, $lv] = $l;
        [, $rv] = $r;

        $result = match (true) {
            $node instanceof Node\Expr\BinaryOp\Identical      => $lv === $rv,
            $node instanceof Node\Expr\BinaryOp\NotIdentical   => $lv !== $rv,
            $node instanceof Node\Expr\BinaryOp\Equal          => $lv == $rv,
            $node instanceof Node\Expr\BinaryOp\NotEqual       => $lv != $rv,
            $node instanceof Node\Expr\BinaryOp\Smaller        => $lv < $rv,
            $node instanceof Node\Expr\BinaryOp\SmallerOrEqual => $lv <= $rv,
            $node instanceof Node\Expr\BinaryOp\Greater        => $lv > $rv,
            $node instanceof Node\Expr\BinaryOp\GreaterOrEqual => $lv >= $rv,
            default                                            => null,
        };

        if ($result === null) {
            return null;
        }

        return new Node\Expr\ConstFetch(new Node\Name($result ? 'true' : 'false'));
    }

    // ── Ternary folding ──────────────────────────────────────────────────────

    private function foldTernary(Node\Expr\Ternary $node): ?Node
    {
        $cond = $this->asScalar($node->cond);
        if ($cond === null) {
            return null;
        }

        if ($cond[1]) {
            // Short ternary ($a ?: $b): when truthy, return the condition itself
            return $node->if ?? $node->cond;
        }

        return $node->else ?? new Node\Expr\ConstFetch(new Node\Name('null'));
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Extracts the PHP value of a scalar/bool/null AST node.
     * Returns [true, value] on success, or null if the node is not a compile-time constant.
     *
     * @return array{0: true, 1: mixed}|null
     */
    private function asScalar(Node $node): ?array
    {
        if (
            $node instanceof Node\Scalar\String_
            || $node instanceof Node\Scalar\LNumber
            || $node instanceof Node\Scalar\DNumber
        ) {
            return [true, $node->value];
        }

        if ($node instanceof Node\Expr\ConstFetch) {
            return match (strtolower($node->name->toString())) {
                'true'  => [true, true],
                'false' => [true, false],
                'null'  => [true, null],
                default => null,
            };
        }

        return null;
    }
}
