<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;

/**
 * Удаляет мёртвые циклы — циклы, единственный эффект которых состоит в мутации
 * переменных, не читаемых после цикла.
 *
 * Обрабатывает два случая:
 * 1. For-цикл с пустым телом: `for ($i = 1; $i <= 3; $i++) {}` — если `$i` не читается после.
 * 2. Do-while/while-цикл, тело которого только мутирует локальные переменные,
 *    не читаемые после цикла: `do { $j++; } while ($j < 2);`
 */
#[VisitorMeta('Удаление мёртвых циклов')]
class RemoveDeadLoopVisitor extends BaseVisitor
{
    #[\Override]
    public function beforeTraverse(array $nodes): ?array
    {
        parent::beforeTraverse($nodes);
        $this->analyzeList($nodes);
        return null;
    }

    // ── Recursive analysis ───────────────────────────────────────────────────

    private function analyzeList(array $stmts): void
    {
        $count = count($stmts);
        for ($i = 0; $i < $count; $i++) {
            $stmt   = $stmts[$i];
            $suffix = array_slice($stmts, $i + 1);

            if ($stmt instanceof Node\Stmt\For_) {
                $this->tryMarkDeadFor($stmt, $suffix);
            } elseif ($stmt instanceof Node\Stmt\Do_) {
                $this->tryMarkDeadDo($stmt, $suffix);
            }

            // Recurse into nested statement lists
            $this->recurseIntoNode($stmt);
        }
    }

    private function recurseIntoNode(Node $node): void
    {
        foreach ($node->getSubNodeNames() as $subName) {
            $sub = $node->$subName;
            if (is_array($sub) && $sub !== [] && $sub[0] instanceof Node\Stmt) {
                $this->analyzeList($sub);
            } elseif (is_array($sub)) {
                foreach ($sub as $item) {
                    if ($item instanceof Node) {
                        $this->recurseIntoNode($item);
                    }
                }
            }
        }
    }

    // ── For detection ────────────────────────────────────────────────────────

    private function tryMarkDeadFor(Node\Stmt\For_ $stmt, array $suffix): void
    {
        // All init/cond/loop expressions must be pure (no I/O, no calls)
        foreach (array_merge($stmt->init, $stmt->cond, $stmt->loop) as $expr) {
            if (!$this->isLoopExprPure($expr)) {
                return;
            }
        }

        $initMutated = $this->mutatedVarsInExprs(array_merge($stmt->init, $stmt->loop));
        $suffixReads = $this->readVarNames($suffix);

        if ($stmt->stmts === []) {
            if (array_intersect($initMutated, $suffixReads) !== []) {
                return;
            }

            $stmt->setAttribute('remove', true);
            return;
        }

        // Non-empty body: check if body only mutates vars not read after
        $bodyMutated = $this->mutatedVarsInStmts($stmt->stmts);
        if ($bodyMutated === null) {
            return;
        }

        $allMutated = array_unique(array_merge($bodyMutated, $initMutated));
        if (array_intersect($allMutated, $suffixReads) !== []) {
            return;
        }

        $stmt->setAttribute('remove', true);
    }

    // ── Do detection ─────────────────────────────────────────────────────────

    private function tryMarkDeadDo(Node\Stmt\Do_ $stmt, array $suffix): void
    {
        if (!$this->isLoopExprPure($stmt->cond)) {
            return;
        }

        $bodyMutated = $this->mutatedVarsInStmts($stmt->stmts);
        if ($bodyMutated === null) {
            return;
        }

        $suffixReads = $this->readVarNames($suffix);
        if (array_intersect($bodyMutated, $suffixReads) !== []) {
            return;
        }

        $stmt->setAttribute('remove', true);
    }

    // ── Purity check ─────────────────────────────────────────────────────────

    private function isLoopExprPure(Node\Expr $expr): bool
    {
        return match (true) {
            $expr instanceof Node\Scalar           => true,
            $expr instanceof Node\Expr\ConstFetch  => true,
            $expr instanceof Node\Expr\Variable    => true,

            $expr instanceof Node\Expr\Assign      =>
                $expr->var instanceof Node\Expr\Variable && $this->isLoopExprPure($expr->expr),

            $expr instanceof Node\Expr\AssignOp    =>
                $expr->var instanceof Node\Expr\Variable && $this->isLoopExprPure($expr->expr),

            $expr instanceof Node\Expr\BinaryOp    =>
                $this->isLoopExprPure($expr->left) && $this->isLoopExprPure($expr->right),

            $expr instanceof Node\Expr\UnaryMinus,
            $expr instanceof Node\Expr\UnaryPlus,
            $expr instanceof Node\Expr\BooleanNot  =>
                $this->isLoopExprPure($expr->expr),

            $expr instanceof Node\Expr\PreInc,
            $expr instanceof Node\Expr\PostInc,
            $expr instanceof Node\Expr\PreDec,
            $expr instanceof Node\Expr\PostDec     =>
                $expr->var instanceof Node\Expr\Variable,

            // new ClassName(pureArgs): treat object construction as pure when args are pure
            $expr instanceof Node\Expr\New_ =>
                $expr->class instanceof Node\Name
                && array_all(
                    $expr->args,
                    fn($a): bool => $a instanceof Node\Arg && $this->isLoopExprPure($a->value)
                ),

            default                                => false,
        };
    }

    // ── Mutation collectors ──────────────────────────────────────────────────

    /** @return array<string> */
    private function mutatedVarsInExprs(array $exprs): array
    {
        $vars = [];
        foreach ($exprs as $expr) {
            foreach ($this->findNode(Node\Expr\Assign::class, [$expr]) as $a) {
                if ($a->var instanceof Node\Expr\Variable && is_string($a->var->name)) {
                    $vars[] = $a->var->name;
                }
            }

            foreach ($this->findNode(Node\Expr\AssignOp::class, [$expr]) as $a) {
                if ($a->var instanceof Node\Expr\Variable && is_string($a->var->name)) {
                    $vars[] = $a->var->name;
                }
            }

            foreach (
                [
                Node\Expr\PreInc::class, Node\Expr\PostInc::class,
                Node\Expr\PreDec::class, Node\Expr\PostDec::class,
                ] as $class
            ) {
                foreach ($this->findNode($class, [$expr]) as $inc) {
                    if ($inc->var instanceof Node\Expr\Variable && is_string($inc->var->name)) {
                        $vars[] = $inc->var->name;
                    }
                }
            }
        }

        return array_unique($vars);
    }

    /**
     * Returns mutated var names if all stmts are pure mutations, null if any has side effects.
     *
     * @return array<string>|null
     */
    private function mutatedVarsInStmts(array $stmts): ?array
    {
        $vars = [];
        foreach ($stmts as $stmt) {
            if (!$stmt instanceof Node\Stmt\Expression) {
                return null;
            }

            $expr = $stmt->expr;
            if (!$this->isLoopExprPure($expr)) {
                return null;
            }

            array_push($vars, ...$this->mutatedVarsInExprs([$expr]));
        }

        return array_unique($vars);
    }

    // ── Read collector ───────────────────────────────────────────────────────

    /** @return array<string> */
    private function readVarNames(array $nodes): array
    {
        $assignLhsIds = [];
        foreach ($this->findNode(Node\Expr\Assign::class, $nodes) as $a) {
            if ($a->var instanceof Node\Expr\Variable) {
                $assignLhsIds[spl_object_id($a->var)] = true;
            }
        }

        $reads = [];
        foreach ($this->findNode(Node\Expr\Variable::class, $nodes) as $var) {
            if (is_string($var->name) && !isset($assignLhsIds[spl_object_id($var)])) {
                $reads[] = $var->name;
            }
        }

        return array_unique($reads);
    }
}
