<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;
use PhpParser\Node\Scalar\String_;

/**
 * Свёртка конкатенации строк и переменных в интерполированную строку.
 *
 * Преобразует цепочки конкатенации, состоящие только из строковых литералов
 * и простых переменных, в PHP double-quoted строку с интерполяцией.
 *
 * Примеры:
 *   echo 'prefix' . $var . "\n"
 *   → echo "prefix{$var}\n"
 *
 *   echo '1234510.231test_test' . ($tmp3 . "\n") . 'success'
 *   → echo "1234510.231test_test{$tmp3}\nsuccess"
 *
 * Если цепочка содержит нефлеттируемые узлы (вызовы функций, методов и т.д.),
 * преобразование не выполняется.
 *
 * Работает снизу вверх (leaveNode): внутренние конкатенации разворачиваются
 * до того, как обрабатываются внешние.
 */
#[VisitorMeta('Свёртка конкатенации строк и переменных в интерполированную строку')]
class ConcatInterpolateVisitor extends BaseVisitor
{
    #[\Override]
    public function leaveNode(Node $node): int|Node|array|null
    {
        $base = parent::leaveNode($node);
        if ($base !== null) {
            return $base;
        }

        if (!($node instanceof Node\Expr\BinaryOp\Concat)) {
            return null;
        }

        $parts = $this->flattenConcat($node);
        if ($parts === null) {
            return null; // contains non-flattenable expressions
        }

        $merged = $this->mergeStringParts($parts);

        // Check whether any variables are present
        $hasVar = false;
        foreach ($merged as $p) {
            if ($p['type'] === 'var') {
                $hasVar = true;
                break;
            }
        }

        // No variables: produce a plain double-quoted string (all literals folded)
        if (!$hasVar) {
            $value = '';
            foreach ($merged as $p) {
                $value .= $p['value'];
            }

            return new Node\Scalar\String_($value, ['kind' => String_::KIND_DOUBLE_QUOTED]);
        }

        // Build Encapsed node from the merged parts
        $encapsedParts = [];
        foreach ($merged as $p) {
            if ($p['type'] === 'string') {
                if ($p['value'] !== '') {
                    $encapsedParts[] = new Node\Scalar\EncapsedStringPart($p['value']);
                }
            } else {
                $encapsedParts[] = new Node\Expr\Variable($p['name']);
            }
        }

        if ($encapsedParts === []) {
            return new Node\Scalar\String_('', ['kind' => String_::KIND_DOUBLE_QUOTED]);
        }

        // Single bare variable — no need to wrap in an encapsed string
        if (count($encapsedParts) === 1 && $encapsedParts[0] instanceof Node\Expr\Variable) {
            return $encapsedParts[0];
        }

        return new Node\Scalar\Encapsed($encapsedParts);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Recursively flattens a concat expression into typed parts.
     * Returns null if any sub-expression cannot be represented in an
     * interpolated string (e.g. function calls, method calls, arrays).
     *
     * @return list<array{type:'string',value:string}|array{type:'var',name:string}>|null
     */
    private function flattenConcat(Node\Expr $expr): ?array
    {
        if ($expr instanceof Node\Expr\BinaryOp\Concat) {
            $left = $this->flattenConcat($expr->left);
            if ($left === null) {
                return null;
            }

            $right = $this->flattenConcat($expr->right);
            if ($right === null) {
                return null;
            }

            return array_merge($left, $right);
        }

        if ($expr instanceof Node\Scalar\String_) {
            return [['type' => 'string', 'value' => $expr->value]];
        }

        // Already an interpolated string — extract its parts
        if ($expr instanceof Node\Scalar\Encapsed) {
            $parts = [];
            foreach ($expr->parts as $part) {
                if ($part instanceof Node\Scalar\EncapsedStringPart) {
                    $parts[] = ['type' => 'string', 'value' => $part->value];
                } elseif ($part instanceof Node\Expr\Variable && is_string($part->name)) {
                    $parts[] = ['type' => 'var', 'name' => $part->name];
                } else {
                    return null; // complex interpolated expression
                }
            }

            return $parts;
        }

        if ($expr instanceof Node\Expr\Variable && is_string($expr->name)) {
            return [['type' => 'var', 'name' => $expr->name]];
        }

        return null; // not flattenable
    }

    /**
     * Merges consecutive 'string' parts into one entry.
     *
     * @param  list<array{type:string,...}> $parts
     * @return list<array{type:string,...}>
     */
    private function mergeStringParts(array $parts): array
    {
        $merged = [];
        foreach ($parts as $part) {
            $last = count($merged) - 1;
            if ($last >= 0 && $part['type'] === 'string' && $merged[$last]['type'] === 'string') {
                $merged[$last]['value'] .= $part['value'];
            } else {
                $merged[] = $part;
            }
        }

        return $merged;
    }
}
