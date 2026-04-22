<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\NodeVisitorAbstract;

/**
 * Сводит тело функции/метода к одному return, подставляя локальные переменные.
 *
 * Паттерн:
 *   $a = <выр>;
 *   $b = <выр, возможно использующее $a>;
 *   return <выр, использующее $a, $b>;
 * →
 *   return <выр с подставленными $a, $b>;
 *
 * Нужен, чтобы многострочные методы (например Point::distanceTo)
 * становились single-return и их мог инлайнить ConstructorAndMethodsVisitor.
 *
 * Ограничения:
 *  - локальная переменная присваивается ровно один раз;
 *  - не перезаписывается параметр метода;
 *  - если RHS содержит побочные эффекты, переменная должна использоваться ровно один раз
 *    (иначе дублирование выражения изменит семантику).
 */
#[VisitorMeta('Свёртка тела функции/метода к одному return (подстановка локальных переменных)')]
class MethodToSingleReturnVisitor extends BaseVisitor
{
    public function enterNode(Node $node): int|Node|array|null
    {
        if (
            !$node instanceof Node\Stmt\ClassMethod
            && !$node instanceof Node\Stmt\Function_
            && !$node instanceof Node\Expr\Closure
        ) {
            return null;
        }

        $stmts = $node->stmts;
        if (!is_array($stmts) || count($stmts) < 2) {
            return null;
        }

        $lastIdx = count($stmts) - 1;
        $last = $stmts[$lastIdx];
        if (!$last instanceof Node\Stmt\Return_ || !$last->expr instanceof Node\Expr) {
            return null;
        }

        $paramNames = [];
        foreach ($node->params as $param) {
            if ($param->var instanceof Node\Expr\Variable && is_string($param->var->name)) {
                $paramNames[$param->var->name] = true;
            }
        }

        /** @var array<string, Node\Expr> $subs */
        $subs = [];

        for ($i = 0; $i < $lastIdx; $i++) {
            $s = $stmts[$i];
            if (!$s instanceof Node\Stmt\Expression) {
                return null;
            }

            $expr = $s->expr;
            if (!$expr instanceof Node\Expr\Assign) {
                return null;
            }

            if (!$expr->var instanceof Node\Expr\Variable || !is_string($expr->var->name)) {
                return null;
            }

            $name = $expr->var->name;

            if (isset($paramNames[$name]) || isset($subs[$name])) {
                return null;
            }

            $subs[$name] = $this->applySubs($expr->expr, $subs);
        }

        $useCounts = $this->countVarUsages($last->expr, array_keys($subs));

        foreach ($subs as $name => $rhs) {
            $count = $useCounts[$name] ?? 0;
            if ($count > 1 && !$this->isPureExpr($rhs)) {
                return null;
            }
        }

        $newReturnExpr = $this->applySubs($last->expr, $subs);
        $node->stmts = [new Node\Stmt\Return_($newReturnExpr, $last->getAttributes())];

        return $node;
    }

    /**
     * Подставляет в клон $expr значения из $subs для соответствующих Variable-узлов.
     *
     * @param array<string, Node\Expr> $subs
     */
    private function applySubs(Node\Expr $expr, array $subs): Node\Expr
    {
        if ($subs === []) {
            return $expr;
        }

        $cloned = self::deepClone($expr);

        $traverser = new NodeTraverser();
        $traverser->addVisitor(
            new class ($subs) extends NodeVisitorAbstract {
                /** @param array<string, Node\Expr> $subs */
                public function __construct(private array $subs)
                {
                }

                public function leaveNode(Node $n): ?Node
                {
                    if ($n instanceof Node\Expr\Variable && is_string($n->name) && isset($this->subs[$n->name])) {
                        return MethodToSingleReturnVisitor::deepClone($this->subs[$n->name]);
                    }
                    return null;
                }
            }
        );

        $result = $traverser->traverse([$cloned]);
        return $result[0] instanceof Node\Expr ? $result[0] : $expr;
    }

    public static function deepClone(Node\Expr $expr): Node\Expr
    {
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new CloningVisitor());
        $result = $traverser->traverse([$expr]);
        return $result[0] instanceof Node\Expr ? $result[0] : $expr;
    }

    /**
     * @param  list<string> $names
     * @return array<string, int>
     */
    private function countVarUsages(Node\Expr $expr, array $names): array
    {
        $counter = new class extends NodeVisitorAbstract {
            /** @var array<string, int> */
            public array $counts = [];

            public function enterNode(Node $n): ?Node
            {
                if ($n instanceof Node\Expr\Variable && is_string($n->name) && array_key_exists($n->name, $this->counts)) {
                    $this->counts[$n->name]++;
                }
                return null;
            }
        };

        foreach ($names as $name) {
            $counter->counts[$name] = 0;
        }

        $traverser = new NodeTraverser();
        $traverser->addVisitor($counter);
        $traverser->traverse([$expr]);

        return $counter->counts;
    }

    private function isPureExpr(Node $expr): bool
    {
        $checker = new class extends NodeVisitorAbstract {
            public bool $pure = true;

            public function enterNode(Node $n): ?int
            {
                if (
                    $n instanceof Node\Expr\FuncCall
                    || $n instanceof Node\Expr\MethodCall
                    || $n instanceof Node\Expr\NullsafeMethodCall
                    || $n instanceof Node\Expr\StaticCall
                    || $n instanceof Node\Expr\New_
                    || $n instanceof Node\Expr\Assign
                    || $n instanceof Node\Expr\AssignOp
                    || $n instanceof Node\Expr\AssignRef
                    || $n instanceof Node\Expr\PreInc
                    || $n instanceof Node\Expr\PostInc
                    || $n instanceof Node\Expr\PreDec
                    || $n instanceof Node\Expr\PostDec
                    || $n instanceof Node\Expr\Yield_
                    || $n instanceof Node\Expr\YieldFrom
                    || $n instanceof Node\Expr\Include_
                    || $n instanceof Node\Expr\Eval_
                    || $n instanceof Node\Expr\Throw_
                ) {
                    $this->pure = false;
                    return NodeTraverser::STOP_TRAVERSAL;
                }
                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($checker);
        $traverser->traverse([$expr]);

        return $checker->pure;
    }
}
