<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;
use PhpParser\NodeFinder;
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
 * - Тело не содержит continue/yield; break (любого уровня) — развёртка оборачивается в
 *   `do { … } while (false)`, который занимает уровень цикла, и уровни break не меняются
 * - Переменная цикла — простое $variable
 */
#[VisitorMeta('Разворачивание foreach по литеральному массиву', targets: [Node\Stmt\Foreach_::class])]
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

        $jumps = new NodeFinder()->find($node->stmts, static fn(Node $n): bool => $n instanceof Node\Stmt\Break_ || $n instanceof Node\Stmt\Continue_
            || $n instanceof Node\Expr\Yield_ || $n instanceof Node\Expr\YieldFrom);
        if (array_any($jumps, static fn(Node $n): bool => !$n instanceof Node\Stmt\Break_) || $this->assignsLoopVar($node)) {
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

        return $jumps === [] ? $result : [new Node\Stmt\Do_(new Node\Expr\ConstFetch(new Node\Name('false')), $result)];
    }

    /** Тело пишет в переменную цикла (или ключа) — подстановка значения неверна. */
    private function assignsLoopVar(Node\Stmt\Foreach_ $node): bool
    {
        $names = array_filter([$node->valueVar, $node->keyVar], static fn(?Node $v): bool => $v instanceof Node\Expr\Variable);
        $names = array_map(static fn(Node\Expr\Variable $v): mixed => $v->name, $names);

        return new NodeFinder()->findFirst($node->stmts, static fn(Node $n): bool => ($n instanceof Node\Expr\Assign || $n instanceof Node\Expr\AssignRef
            || $n instanceof Node\Expr\AssignOp) && $n->var instanceof Node\Expr\Variable && in_array($n->var->name, $names, true)
            || $n instanceof Node\Expr\Variable && !is_string($n->name)) instanceof Node;
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

            public function enterNode(Node $node): ?Node
            {
                // 'C'::m(), new 'C' — на месте класса имя (до замены самой переменной)
                if (
                    ($node instanceof Node\Expr\StaticCall || $node instanceof Node\Expr\ClassConstFetch || $node instanceof Node\Expr\StaticPropertyFetch
                        || $node instanceof Node\Expr\New_) && $node->class instanceof Node\Expr\Variable && $node->class->name === $this->varName
                    && $this->replacement instanceof Node\Scalar\String_ && preg_match('/^\\\\?[A-Za-z_][\w\\\\]*$/', $this->replacement->value) === 1
                ) {
                    $node->class = new Node\Name\FullyQualified(ltrim($this->replacement->value, '\\'));

                    return $node;
                }

                return null;
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
