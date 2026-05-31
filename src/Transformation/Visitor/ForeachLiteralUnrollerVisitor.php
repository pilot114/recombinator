<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * Разворачивает foreach по литеральному массиву в последовательность инструкций:
 *
 *   foreach ([$rex, $milo, $whiskers] as $animal) { ... }
 *   →
 *   { ...body with $animal = $rex... }
 *   { ...body with $animal = $milo... }
 *   { ...body with $animal = $whiskers... }
 *
 * Условия для разворачивания:
 * - Итерируемое выражение — литеральный Array_ (не переменная, не вызов)
 * - Не более 20 элементов (защита от взрыва)
 * - Тело не содержит break/continue/return/yield/throw
 * - Переменная цикла — простое $variable
 */
#[VisitorMeta('Разворачивание foreach по литеральному массиву')]
class ForeachLiteralUnrollerVisitor extends BaseVisitor
{
    private const int MAX_ELEMENTS = 20;

    #[\Override]
    public function leaveNode(Node $node): int|Node|array|null
    {
        if (!$node instanceof Node\Stmt\Foreach_) {
            return parent::leaveNode($node);
        }

        if (!$node->expr instanceof Node\Expr\Array_) {
            return parent::leaveNode($node);
        }

        $items = $node->expr->items;
        if (count($items) > self::MAX_ELEMENTS || $items === []) {
            return parent::leaveNode($node);
        }

        if (!$node->valueVar instanceof Node\Expr\Variable || !is_string($node->valueVar->name)) {
            return parent::leaveNode($node);
        }

        if ($this->hasFlowControl($node->stmts)) {
            return parent::leaveNode($node);
        }

        $keyVarName = null;
        if ($node->keyVar instanceof Node\Expr\Variable && is_string($node->keyVar->name)) {
            $keyVarName = $node->keyVar->name;
        }

        $result = [];
        $autoIndex = 0;

        foreach ($items as $item) {
            if (!$item instanceof Node\ArrayItem) {
                return parent::leaveNode($node);
            }

            $elemExpr = $this->deepClone($item->value);
            $keyExpr  = $item->key instanceof \PhpParser\Node\Expr
                ? $this->deepClone($item->key)
                : new Node\Scalar\LNumber($autoIndex);

            $cloned = $this->cloneStmts($node->stmts);
            $cloned = $this->substitute($cloned, $node->valueVar->name, $elemExpr);

            if ($keyVarName !== null) {
                $cloned = $this->substitute($cloned, $keyVarName, $keyExpr);
            }

            foreach ($cloned as $stmt) {
                $result[] = $stmt;
            }

            $autoIndex++;
        }

        return $result;
    }

    /**
     * Returns true if the statement list contains break/continue/return/yield/throw
     * at any nesting level (conservative — don't unroll if uncertain).
     */
    private function hasFlowControl(array $stmts): bool
    {
        foreach ($stmts as $stmt) {
            if (
                $stmt instanceof Node\Stmt\Break_
                || $stmt instanceof Node\Stmt\Continue_
                || $stmt instanceof Node\Stmt\Return_
                || $stmt instanceof Node\Stmt\Throw_
            ) {
                return true;
            }

            if ($stmt instanceof Node\Stmt\Expression) {
                $expr = $stmt->expr;
                if ($expr instanceof Node\Expr\Yield_ || $expr instanceof Node\Expr\YieldFrom_) {
                    return true;
                }
            }

            // Recurse into nested blocks
            if ($stmt instanceof Node\Stmt\If_) {
                if ($this->hasFlowControl($stmt->stmts)) {
                    return true;
                }

                foreach ($stmt->elseifs as $elseif) {
                    if ($this->hasFlowControl($elseif->stmts)) {
                        return true;
                    }
                }

                if ($stmt->else instanceof \PhpParser\Node\Stmt\Else_ && $this->hasFlowControl($stmt->else->stmts)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Deep-clones an array of statements using the CloningVisitor strategy.
     *
     * @param  Node\Stmt[] $stmts
     * @return Node\Stmt[]
     */
    private function cloneStmts(array $stmts): array
    {
        return array_map($this->deepClone(...), $stmts);
    }

    /**
     * Substitutes all occurrences of variable $varName with $replacement in $stmts.
     *
     * @param  Node\Stmt[]  $stmts
     * @return Node\Stmt[]
     */
    private function substitute(array $stmts, string $varName, Node\Expr $replacement): array
    {
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new class ($varName, $replacement) extends NodeVisitorAbstract {
            public function __construct(
                private readonly string $varName,
                private readonly Node\Expr $replacement,
            ) {
            }

            public function leaveNode(Node $node): ?Node
            {
                if ($node instanceof Node\Expr\Variable && $node->name === $this->varName) {
                    return clone $this->replacement;
                }

                return null;
            }
        });

        return $traverser->traverse($stmts);
    }
}
