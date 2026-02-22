<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;

/**
 * Улучшение читаемости: вынос вложенных составных выражений в $tmp-переменные.
 *
 * Извлекаются два класса выражений, когда они являются вложенными подвыражениями
 * (внутри конкатенации, аргумента функции, массива и т.д.):
 *
 * 1. Тернарный оператор
 *      echo 'x' . ($a === 'y' ? 'ok' : 'fail') . "\n";
 *    →
 *      $tmp1 = $a === 'y' ? 'ok' : 'fail';
 *      echo 'x' . $tmp1 . "\n";
 *
 * 2. Оператор ?? (null-coalescing)
 *      echo ($_GET['x'] ?? 'default') . "\n";
 *    →
 *      $tmp1 = $_GET['x'] ?? 'default';
 *      echo $tmp1 . "\n";
 *
 * НЕ трогает выражения, которые уже стоят на верхнем уровне оператора
 * (прямо после =, return, или единственный аргумент echo).
 *
 * Работает снизу вверх (leaveNode), поэтому вложенные выражения разворачиваются
 * изнутри наружу за один проход.
 */
#[VisitorMeta('Вынос вложенных тернарных операторов и ?? в $tmp-переменные')]
class ReadabilityVisitor extends BaseVisitor
{
    private int $counter = 0;

    #[\Override]
    public function leaveNode(Node $node): int|Node|array|null
    {
        // Let BaseVisitor handle 'remove' / 'replace' attributes first
        $base = parent::leaveNode($node);
        if ($base !== null) {
            return $base;
        }

        // Only process statement nodes that contain expressions
        if (
            !($node instanceof Node\Stmt\Expression)
            && !($node instanceof Node\Stmt\Echo_)
            && !($node instanceof Node\Stmt\Return_)
        ) {
            return null;
        }

        // Expression sitting directly at statement level — already readable, skip it
        $skipExpr = $this->directExtractable($node);

        // Collect nested extractable expressions, innermost first
        $extractable = [];
        $this->collectExtractable($node, $skipExpr, $extractable);

        if ($extractable === []) {
            return null;
        }

        $prepend = [];
        foreach ($extractable as $expr) {
            $varName = 'tmp' . (++$this->counter);
            $var     = new Node\Expr\Variable($varName);

            // $tmpN = <expr>;
            $prepend[] = new Node\Stmt\Expression(
                new Node\Expr\Assign(clone $var, $expr)
            );

            // Replace the expression in the current statement with $tmpN
            $this->substituteByIdentity($node, $expr, $var);
        }

        return array_merge($prepend, [$node]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Returns true for expression types that should be extracted to $tmp variables
     * when they appear as nested sub-expressions.
     */
    private function isExtractable(mixed $node): bool
    {
        return $node instanceof Node\Expr\Ternary
            || $node instanceof Node\Expr\BinaryOp\Coalesce;
    }

    /**
     * Returns the extractable expression sitting directly at statement level
     * (should NOT be re-extracted — it is already readable as-is).
     *
     * Covered patterns:
     *   $x = <expr>;           (assignment)
     *   return <expr>;
     *   echo <expr>;           (single-expression echo)
     */
    private function directExtractable(Node $stmt): ?Node\Expr
    {
        // $x = <expr>;
        if (
            $stmt instanceof Node\Stmt\Expression
            && $stmt->expr instanceof Node\Expr\Assign
        ) {
            $rhs = $stmt->expr->expr;
            if ($this->isExtractable($rhs)) {
                return $rhs;
            }
        }

        // return <expr>;
        if ($stmt instanceof Node\Stmt\Return_ && $stmt->expr instanceof \PhpParser\Node\Expr && $this->isExtractable($stmt->expr)) {
            return $stmt->expr;
        }

        // echo <expr>;  — single-expression echo
        if (
            $stmt instanceof Node\Stmt\Echo_
            && count($stmt->exprs) === 1
            && $this->isExtractable($stmt->exprs[0])
        ) {
            return $stmt->exprs[0];
        }

        return null;
    }

    /**
     * Recursively collects extractable nodes in bottom-up order (innermost first),
     * skipping the top-level $skip node (it is already at statement level).
     *
     * @param array<Node\Expr> $result
     */
    private function collectExtractable(Node $node, ?Node $skip, array &$result): void
    {
        foreach ($node->getSubNodeNames() as $subName) {
            $value = $node->$subName;

            if ($this->isExtractable($value) && $value !== $skip) {
                $this->collectExtractable($value, null, $result); // inner first
                $result[] = $value;
            } elseif ($value instanceof Node) {
                $this->collectExtractable($value, $skip, $result);
            } elseif (is_array($value)) {
                foreach ($value as $item) {
                    if ($this->isExtractable($item) && $item !== $skip) {
                        $this->collectExtractable($item, null, $result);
                        $result[] = $item;
                    } elseif ($item instanceof Node) {
                        $this->collectExtractable($item, $skip, $result);
                    }
                }
            }
        }
    }

    /**
     * Replaces $target (by object identity ===) with $replacement anywhere in $root's subtree.
     * Stops after the first match (each node object appears exactly once).
     */
    private function substituteByIdentity(Node $root, Node $target, Node $replacement): bool
    {
        foreach ($root->getSubNodeNames() as $subName) {
            $value = $root->$subName;

            if ($value === $target) {
                $root->$subName = $replacement;
                return true;
            }

            if ($value instanceof Node) {
                if ($this->substituteByIdentity($value, $target, $replacement)) {
                    return true;
                }
            } elseif (is_array($value)) {
                foreach ($value as $k => $item) {
                    if ($item === $target) {
                        $value[$k] = $replacement;
                        $root->$subName = $value;
                        return true;
                    }

                    if ($item instanceof Node && $this->substituteByIdentity($item, $target, $replacement)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
