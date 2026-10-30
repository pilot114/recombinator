<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;

/**
 * Свёртка константных выражений на этапе компиляции:
 *
 * 1. Сравнения скаляров → true/false
 * 2. Арифметика скаляров → результат (для целых и float)
 * 3. Тождественные операции: 0+x, x+0, x-0, x*1, 1*x, x/1 → x
 * 4. Тернарный оператор с константным условием → соответствующая ветка
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
            $folded = $this->foldBinaryOp($node);
            if ($folded instanceof \PhpParser\Node) {
                return $folded;
            }
        }

        if ($node instanceof Node\Expr\BooleanNot) {
            $inner = $this->asScalar($node->expr);
            if ($inner !== null) {
                return $this->scalarToNode(!$inner[1]);
            }
        }

        // (string) 'x', (int) 1 — приведение литерала к его же типу (след встроенного параметра)
        if (
            $node instanceof Node\Expr\Cast\String_ && $node->expr instanceof Node\Scalar\String_
            || $node instanceof Node\Expr\Cast\Int_ && $node->expr instanceof Node\Scalar\Int_
            || $node instanceof Node\Expr\Cast\Double && $node->expr instanceof Node\Scalar\Float_
            || $node instanceof Node\Expr\Cast\Bool_ && $node->expr instanceof Node\Expr\ConstFetch
                && in_array($node->expr->name->toLowerString(), ['true', 'false'], true)
        ) {
            return $node->expr;
        }

        if ($node instanceof Node\Expr\Ternary) {
            $folded = $this->foldTernary($node);
            if ($folded instanceof \PhpParser\Node) {
                return $folded;
            }
        }

        // isset(['a' => 1]['a']) → true; isset(['a' => null]['a']), isset([...]['нет']) → false
        if ($node instanceof Node\Expr\Isset_ && count($node->vars) === 1 && $node->vars[0] instanceof Node\Expr\ArrayDimFetch) {
            $present = $this->literalElement($node->vars[0]);
            if ($present !== false) {
                $set = $present instanceof Node\Expr && (!$present instanceof Node\Expr\ConstFetch || $present->name->toLowerString() !== 'null');

                return new Node\Expr\ConstFetch(new Node\Name($set ? 'true' : 'false'));
            }
        }

        // ['a' => ['b' => true]]['a']['b'] → true (не внутри isset/unset и не как цель записи)
        $parent = $node->getAttribute('parent');
        $target = $parent instanceof Node\Expr\Isset_ || $parent instanceof Node\Stmt\Unset_
            || ($parent instanceof Node\Expr\Assign || $parent instanceof Node\Expr\AssignOp || $parent instanceof Node\Expr\AssignRef) && $parent->var === $node;
        if ($node instanceof Node\Expr\ArrayDimFetch && !$target) {
            $folded = $this->foldArrayDim($node);
            if ($folded instanceof Node\Expr) {
                return $folded;
            }
        }

        // Интерполированная строка без переменных (все части — константы) → String_
        if ($node instanceof Node\Scalar\Encapsed) {
            $folded = $this->foldEncapsed($node);
            if ($folded instanceof \PhpParser\Node) {
                return $folded;
            }
        }

        return parent::leaveNode($node);
    }

    /**
     * Элемент литерального массива по литеральному ключу. Ключи приводятся как в PHP
     * ("1" → 1, true → 1, null → ""), без ключа — следующий целый индекс, повтор —
     * последний. Отброшенные элементы не должны иметь побочных эффектов; отсутствующий
     * ключ не сворачивается (предупреждение «Undefined array key»).
     */
    private function foldArrayDim(Node\Expr\ArrayDimFetch $node): ?Node\Expr
    {
        $element = $this->literalElement($node);

        return $element instanceof Node\Expr ? $this->deepClone($element) : null;
    }

    /**
     * Элемент литерального массива по литеральному ключу: значение; null — ключа нет;
     * false — массив или ключ не литеральные.
     */
    private function literalElement(Node\Expr\ArrayDimFetch $node): Node\Expr|false|null
    {
        if (!$node->var instanceof Node\Expr\Array_ || !$node->dim instanceof Node\Expr) {
            return false;
        }

        $dim = $this->asScalar($node->dim);
        if ($dim === null || is_float($dim[1])) {
            return false;
        }

        $values = [];
        $next   = 0;
        foreach ($node->var->items as $item) {
            if (!$item instanceof Node\ArrayItem || $item->byRef || $item->unpack || !$this->isEffectFree($item->value)) {
                return false;
            }

            if ($item->key instanceof Node\Expr) {
                $key = $this->asScalar($item->key);
                if ($key === null || is_float($key[1])) {
                    return false;
                }

                $key = $this->arrayKey($key[1]);
            } else {
                $key = $next;
            }

            if (is_int($key) && $key >= $next) {
                $next = $key + 1;
            }

            $values[$key] = $item->value;
        }

        $wanted = $this->arrayKey($dim[1]);

        return $values[$wanted] ?? null;
    }

    private function arrayKey(mixed $key): int|string
    {
        $key = match (true) {
            is_bool($key) => (int) $key,
            $key === null => '',
            default => $key,
        };

        // Десятичная строка без ведущих нулей — целый ключ, как в PHP
        if (is_string($key) && preg_match('/^(0|-?[1-9]\d*)$/', $key) === 1 && (string) (int) $key === $key) {
            return (int) $key;
        }

        return is_int($key) ? $key : (string) $key;
    }

    /** Литералы, константы и их комбинации — отбросить их можно без последствий. */
    private function isEffectFree(Node\Expr $expr): bool
    {
        if ($expr instanceof Node\Scalar || $expr instanceof Node\Expr\ConstFetch || $expr instanceof Node\Expr\ClassConstFetch) {
            return true;
        }

        if ($expr instanceof Node\Expr\BinaryOp) {
            return $this->isEffectFree($expr->left) && $this->isEffectFree($expr->right);
        }

        if ($expr instanceof Node\Expr\UnaryMinus || $expr instanceof Node\Expr\BooleanNot) {
            return $this->isEffectFree($expr->expr);
        }

        return $expr instanceof Node\Expr\Array_ && array_all(
            $expr->items,
            fn(Node\ArrayItem $item): bool => !$item->unpack
                && $this->isEffectFree($item->value) && (!$item->key instanceof Node\Expr || $this->isEffectFree($item->key)),
        );
    }

    // ── Binary operation folding ─────────────────────────────────────────────

    private function foldBinaryOp(Node\Expr\BinaryOp $node): ?Node
    {
        $l = $this->asScalar($node->left);
        $r = $this->asScalar($node->right);

        // Both sides are scalars — fully evaluate
        if ($l !== null && $r !== null) {
            [, $lv] = $l;
            [, $rv] = $r;

            $result = match (true) {
                // Comparisons → bool
                $node instanceof Node\Expr\BinaryOp\Identical      => $lv === $rv,
                $node instanceof Node\Expr\BinaryOp\NotIdentical   => $lv !== $rv,
                $node instanceof Node\Expr\BinaryOp\Equal          => $lv == $rv,
                $node instanceof Node\Expr\BinaryOp\NotEqual       => $lv != $rv,
                $node instanceof Node\Expr\BinaryOp\Smaller        => $lv < $rv,
                $node instanceof Node\Expr\BinaryOp\SmallerOrEqual => $lv <= $rv,
                $node instanceof Node\Expr\BinaryOp\Greater        => $lv > $rv,
                $node instanceof Node\Expr\BinaryOp\GreaterOrEqual => $lv >= $rv,
                $node instanceof Node\Expr\BinaryOp\Spaceship      => $lv <=> $rv,
                // Logic → bool
                $node instanceof Node\Expr\BinaryOp\BooleanAnd => $lv && $rv,
                $node instanceof Node\Expr\BinaryOp\BooleanOr  => $lv || $rv,
                $node instanceof Node\Expr\BinaryOp\LogicalAnd => $lv && $rv,
                $node instanceof Node\Expr\BinaryOp\LogicalOr  => $lv || $rv,
                // Arithmetic → number
                $node instanceof Node\Expr\BinaryOp\Plus  => $lv + $rv,
                $node instanceof Node\Expr\BinaryOp\Minus => $lv - $rv,
                $node instanceof Node\Expr\BinaryOp\Mul   => $lv * $rv,
                $node instanceof Node\Expr\BinaryOp\Mod   => is_int($rv) && $rv !== 0 ? $lv % $rv : null,
                $node instanceof Node\Expr\BinaryOp\Pow   => $lv ** $rv,
                $node instanceof Node\Expr\BinaryOp\Div   => is_numeric($rv) && $rv != 0 ? $lv / $rv : null,
                // String
                $node instanceof Node\Expr\BinaryOp\Concat => $lv . $rv,
                default => null,
            };

            if ($result === null) {
                return null;
            }

            return $this->scalarToNode($result);
        }

        // One side is scalar — identity simplifications
        if ($l !== null) {
            [, $lv] = $l;

            // Short-circuit: false && x → false,  true || x → true
            $isBoolAnd = $node instanceof Node\Expr\BinaryOp\BooleanAnd || $node instanceof Node\Expr\BinaryOp\LogicalAnd;
            $isBoolOr  = $node instanceof Node\Expr\BinaryOp\BooleanOr  || $node instanceof Node\Expr\BinaryOp\LogicalOr;
            if ($isBoolAnd && !$lv) {
                return $this->scalarToNode(false);
            }

            if ($isBoolOr && $lv) {
                return $this->scalarToNode(true);
            }

            // 0 + x → x,  1 * x → x,  0 . x → (string)x (only if string)
            if ($node instanceof Node\Expr\BinaryOp\Plus && $lv === 0) {
                return $node->right;
            }

            if ($node instanceof Node\Expr\BinaryOp\Mul && $lv === 1) {
                return $node->right;
            }

            if ($node instanceof Node\Expr\BinaryOp\Concat && $lv === '') {
                return $node->right;
            }
        }

        if ($r !== null) {
            [, $rv] = $r;

            // Short-circuit: x && false → false,  x || true → true
            $isBoolAnd = $node instanceof Node\Expr\BinaryOp\BooleanAnd || $node instanceof Node\Expr\BinaryOp\LogicalAnd;
            $isBoolOr  = $node instanceof Node\Expr\BinaryOp\BooleanOr  || $node instanceof Node\Expr\BinaryOp\LogicalOr;
            if ($isBoolAnd && !$rv) {
                return $this->scalarToNode(false);
            }

            if ($isBoolOr && $rv) {
                return $this->scalarToNode(true);
            }

            // x + 0 → x,  x - 0 → x,  x * 1 → x,  x / 1 → x,  x . '' → x
            if (($node instanceof Node\Expr\BinaryOp\Plus || $node instanceof Node\Expr\BinaryOp\Minus) && $rv === 0) {
                return $node->left;
            }

            if (($node instanceof Node\Expr\BinaryOp\Mul || $node instanceof Node\Expr\BinaryOp\Div) && $rv === 1) {
                return $node->left;
            }

            if ($node instanceof Node\Expr\BinaryOp\Concat && $rv === '') {
                return $node->left;
            }

            // (X . 'a') . 'b'  →  X . 'ab'
            // Allows collapsing adjacent string literals in left-associative chains.
            if ($node instanceof Node\Expr\BinaryOp\Concat && is_string($rv) && $node->left instanceof Node\Expr\BinaryOp\Concat) {
                $innerRight = $this->asScalar($node->left->right);
                if ($innerRight !== null && is_string($innerRight[1])) {
                    return new Node\Expr\BinaryOp\Concat(
                        $node->left->left,
                        new Node\Scalar\String_($innerRight[1] . $rv)
                    );
                }
            }
        }

        return null;
    }

    private function scalarToNode(mixed $value): Node\Expr
    {
        if (is_bool($value)) {
            return new Node\Expr\ConstFetch(new Node\Name($value ? 'true' : 'false'));
        }

        if (is_int($value)) {
            return new Node\Scalar\LNumber($value);
        }

        if (is_float($value)) {
            return new Node\Scalar\DNumber($value);
        }

        if (is_string($value)) {
            return new Node\Scalar\String_($value);
        }

        return new Node\Expr\ConstFetch(new Node\Name('null'));
    }

    // ── Encapsed folding ─────────────────────────────────────────────────────

    private function foldEncapsed(Node\Scalar\Encapsed $node): ?Node\Scalar\String_
    {
        $value = '';
        foreach ($node->parts as $part) {
            if ($part instanceof Node\InterpolatedStringPart) {
                $value .= $part->value;
            } elseif ($part instanceof Node\Scalar\String_) {
                $value .= $part->value;
            } elseif ($part instanceof Node\Scalar\LNumber) {
                $value .= (string) $part->value;
            } elseif ($part instanceof Node\Scalar\DNumber) {
                $value .= (string) $part->value;
            } elseif ($part instanceof Node\Expr\ConstFetch) {
                $scalar = $this->asScalar($part);
                if ($scalar === null) {
                    return null;
                }

                $value .= (string) $scalar[1];
            } else {
                return null;
            }
        }

        return new Node\Scalar\String_($value);
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
