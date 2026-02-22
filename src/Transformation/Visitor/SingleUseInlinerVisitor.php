<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;
use PhpParser\NodeTraverser;

/**
 * Инлайнинг одноразовых переменных-посредников.
 *
 * Если переменная присваивается ровно один раз и читается ровно один раз,
 * выражение из правой части подставляется прямо в место чтения,
 * а присваивание удаляется.
 *
 * Пример:
 *   $result = $a . '_' . $b === 'x' ? 'ok' : 'fail';
 *   echo $result . "\n";
 * →
 *   echo ($a . '_' . $b === 'x' ? 'ok' : 'fail') . "\n";
 *
 * Анализ (beforeTraverse) выполняется по уже расставленным ссылкам
 * на родителей от NodeConnectingVisitor, поэтому работает корректно
 * в рамках стандартного конвейера applyVisitor().
 */
#[VisitorMeta('Инлайнинг одноразовых переменных (writeCount=1, readCount=1)')]
class SingleUseInlinerVisitor extends BaseVisitor
{
    /** @var array<string, Node\Expr> varName → expression to inline */
    private array $toInline = [];

    // ── Analysis phase ───────────────────────────────────────────────────────

    #[\Override]
    public function beforeTraverse(array $nodes): ?array
    {
        parent::beforeTraverse($nodes);

        /** @var array<string, int> */
        $writeCounts = [];
        /** @var array<string, int> */
        $readCounts = [];
        /** @var array<string, Node\Expr> */
        $assignExprs = [];
        /** @var array<string, Node\Expr\Assign> */
        $assignNodes = [];
        /** @var array<string, list<Node\Expr\Variable>> */
        $readVarNodes = [];

        // NodeConnectingVisitor has already run — parent refs are valid.
        foreach ($this->findNode(Node\Expr\Variable::class) as $var) {
            if (!is_string($var->name)) {
                continue;
            }

            $name   = $var->name;
            $parent = $var->getAttribute('parent');

            if ($parent instanceof Node\Expr\Assign && $parent->var === $var) {
                // Write: $name = expr
                $writeCounts[$name]  = ($writeCounts[$name] ?? 0) + 1;
                $assignExprs[$name]  = $parent->expr;
                $assignNodes[$name]  = $parent;
            } else {
                // Read
                $readCounts[$name]    = ($readCounts[$name] ?? 0) + 1;
                $readVarNodes[$name][] = $var;
            }
        }

        foreach ($writeCounts as $name => $writeCount) {
            $readCount = $readCounts[$name] ?? 0;
            if ($writeCount !== 1) {
                continue;
            }

            if ($readCount !== 1) {
                continue;
            }

            // The single read must not be inside the assignment's own RHS
            // (e.g. $x = $x + 1 has read inside the assign — skip it)
            $readNode   = $readVarNodes[$name][0];
            $assignNode = $assignNodes[$name];
            if ($this->isInsideNode($readNode, $assignNode)) {
                continue;
            }

            // Mark the wrapping Stmt\Expression for removal
            $stmtParent = $assignNode->getAttribute('parent');
            if (!($stmtParent instanceof Node\Stmt\Expression)) {
                continue; // assignment not in a simple expression statement — skip
            }

            $stmtParent->setAttribute('remove', true);
            $this->toInline[$name] = $assignNode->expr;
        }

        return null;
    }

    // ── Transform phase ──────────────────────────────────────────────────────

    #[\Override]
    public function enterNode(Node $node): ?Node
    {
        if (!($node instanceof Node\Expr\Variable) || !is_string($node->name)) {
            return null;
        }

        $name = $node->name;
        if (!isset($this->toInline[$name])) {
            return null;
        }

        // Skip the LHS of an assignment (write context)
        $parent = $node->getAttribute('parent');
        if ($parent instanceof Node\Expr\Assign && $parent->var === $node) {
            return null;
        }

        // Inline: replace the read with a deep clone of the stored expression
        $expr = $this->deepClone($this->toInline[$name]);
        unset($this->toInline[$name]); // consume — only substitute once

        return $expr;
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Returns true if $child is a descendant of $container in the AST
     * (using the parent refs set by NodeConnectingVisitor).
     */
    private function isInsideNode(Node $child, Node $container): bool
    {
        $current = $child->getAttribute('parent');
        while ($current instanceof Node) {
            if ($current === $container) {
                return true;
            }

            $current = $current->getAttribute('parent');
        }

        return false;
    }

    /**
     * Recursively clones an AST node and all its children.
     * Needed to avoid sharing node objects between two AST positions.
     */
    private function deepClone(Node $node): Node
    {
        $cloned = clone $node;
        foreach ($cloned->getSubNodeNames() as $subName) {
            $value = $cloned->$subName;
            if ($value instanceof Node) {
                $cloned->$subName = $this->deepClone($value);
            } elseif (is_array($value)) {
                foreach ($value as $k => $item) {
                    if ($item instanceof Node) {
                        $value[$k] = $this->deepClone($item);
                    }
                }

                $cloned->$subName = $value;
            }
        }

        return $cloned;
    }
}
