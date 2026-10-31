<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Modifiers;
use PhpParser\Node;
use Recombinator\Transformation\BodyInliner;

/**
 * Встраивает процедуры объекта с известным классом, сохраняя сам объект:
 *
 *   $cnt = new Container([...]);
 *   $doctrine1 = new Doctrine();
 *   $cnt->register($doctrine1);         // register(P $p) { $p->provide($this); }
 *   →
 *   $doctrine1->provide($cnt);
 *
 * В отличие от ClassInlinerVisitor класс не удаляется: объект может передаваться
 * дальше ($this → $cnt). Поэтому встраиваются только методы, чей результат остаётся
 * корректным кодом вне класса: через $this — только публичные свойства и методы
 * либо сам объект целиком.
 *
 * Условия:
 * - переменная один раз присвоена на верхнем уровне через `$v = new C(...)`;
 * - вызов — отдельный оператор верхнего уровня после этого присваивания;
 * - метод — процедура (нет return в собственной области), без yield, parent::,
 *   динамических переменных; не вызывает сам себя.
 */
#[VisitorMeta('Встраивание процедур объекта: $obj->m(args); → тело метода с $this → $obj', targets: [Node\Expr\MethodCall::class])]
class ObjectMethodInlinerVisitor extends BaseVisitor
{
    private const int MAX_DEPTH = 8;

    /** @var array<string, Node\Stmt\Class_> lowercase имя → класс */
    private array $classes = [];

    #[\Override]
    public function beforeTraverse(array $nodes): ?array
    {
        parent::beforeTraverse($nodes);
        $this->classes = [];
        foreach ($this->findNode(Node\Stmt\Class_::class) as $class) {
            if ($class->name instanceof Node\Identifier) {
                $this->classes[strtolower($class->name->toString())] = $class;
            }
        }

        if ($this->classes === []) {
            return null;
        }

        $assignCount = self::topLevelAssignCounts($nodes);
        $callerVars  = BodyInliner::ownScopeVars($nodes);

        /** @var array<string, Node\Stmt\Class_> $known */
        $known   = [];
        $changed = false;
        $result  = [];
        foreach ($nodes as $stmt) {
            $class = $this->newAssignment($stmt);
            if ($class !== null && ($assignCount[$class[0]] ?? 0) === 1) {
                $known[$class[0]] = $class[1];
            }

            $inlined = $this->inlineCall($stmt, $known, $callerVars);
            if ($inlined === null) {
                $result[] = $stmt;
                continue;
            }

            array_push($result, ...$inlined);
            $changed = true;
        }

        return $changed ? $result : null;
    }

    /**
     * @param  array<string, Node\Stmt\Class_> $known
     * @param  array<string, true>             $callerVars
     * @return array<Node\Stmt>|null
     */
    private function inlineCall(Node $stmt, array $known, array &$callerVars): ?array
    {
        if (
            !$stmt instanceof Node\Stmt\Expression
            || !$stmt->expr instanceof Node\Expr\MethodCall
            || !$stmt->expr->var instanceof Node\Expr\Variable
            || !is_string($stmt->expr->var->name)
            || !$stmt->expr->name instanceof Node\Identifier
            || !isset($known[$stmt->expr->var->name])
        ) {
            return null;
        }

        $depth = $stmt->getAttribute('inlineDepth', 0);
        $depth = is_int($depth) ? $depth : 0;
        if ($depth >= self::MAX_DEPTH) {
            return null;
        }

        $call   = $stmt->expr;
        $class  = $known[$stmt->expr->var->name];
        $method = $call->name instanceof Node\Identifier ? $class->getMethod($call->name->toString()) : null;
        if (
            !$method instanceof Node\Stmt\ClassMethod
            || $method->stmts === null
            || $method->isStatic()
            || $method->isAbstract()
            || $method->byRef
            || !$this->isSafeThisUsage($method, $class)
        ) {
            return null;
        }

        $stmts = BodyInliner::inline(
            $method->params,
            $call->args,
            $method->stmts,
            $method->name->toString(),
            new Node\Expr\Variable($stmt->expr->var->name),
            (string) $class->name,
            $callerVars,
        );
        if ($stmts === null) {
            return null;
        }

        foreach ($stmts as $s) {
            $s->setAttribute('inlineDepth', $depth + 1);
        }

        return $stmts;
    }

    /**
     * $this можно заменить переменной объекта, если через него идут обращения
     * только к публичным членам (или объект передаётся целиком) и нет рекурсии.
     */
    private function isSafeThisUsage(Node\Stmt\ClassMethod $method, Node\Stmt\Class_ $class): bool
    {
        $publicProps = [];
        foreach ($class->getProperties() as $property) {
            if ($property->isPublic() && !$property->isStatic()) {
                foreach ($property->props as $prop) {
                    $publicProps[strtolower($prop->name->toString())] = true;
                }
            }
        }

        $ctor = $class->getMethod('__construct');
        foreach ($ctor->params ?? [] as $param) {
            if (($param->flags & Modifiers::PUBLIC) !== 0 && $param->var instanceof Node\Expr\Variable && is_string($param->var->name)) {
                $publicProps[strtolower($param->var->name)] = true;
            }
        }

        $ok = true;
        BodyInliner::walkOwnScope($method->stmts ?? [], function (Node $node) use (&$ok, $publicProps, $class, $method): void {
            if (
                ($node instanceof Node\Expr\PropertyFetch || $node instanceof Node\Expr\NullsafePropertyFetch)
                && $node->var instanceof Node\Expr\Variable
                && $node->var->name === 'this'
                && (!$node->name instanceof Node\Identifier || !isset($publicProps[strtolower($node->name->toString())]))
            ) {
                $ok = false;
            }

            if (
                ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\NullsafeMethodCall)
                && $node->var instanceof Node\Expr\Variable
                && $node->var->name === 'this'
            ) {
                $target = $node->name instanceof Node\Identifier ? $class->getMethod($node->name->toString()) : null;
                if (
                    !$target instanceof Node\Stmt\ClassMethod
                    || !$target->isPublic()
                    || strtolower($target->name->toString()) === strtolower($method->name->toString())
                ) {
                    $ok = false;
                }
            }
        });

        return $ok;
    }

    /** @return array{string, Node\Stmt\Class_}|null */
    private function newAssignment(Node $stmt): ?array
    {
        if (
            $stmt instanceof Node\Stmt\Expression
            && $stmt->expr instanceof Node\Expr\Assign
            && $stmt->expr->var instanceof Node\Expr\Variable
            && is_string($stmt->expr->var->name)
            && $stmt->expr->expr instanceof Node\Expr\New_
            && $stmt->expr->expr->class instanceof Node\Name
        ) {
            $class = $this->classes[strtolower($stmt->expr->expr->class->toString())] ?? null;
            if ($class instanceof Node\Stmt\Class_ && !$class->isAbstract()) {
                return [$stmt->expr->var->name, $class];
            }
        }

        return null;
    }

    /**
     * Сколько раз каждая переменная верхнего уровня перезаписывается.
     *
     * @param  array<Node> $nodes
     * @return array<string, int>
     */
    public static function topLevelAssignCounts(array $nodes): array
    {
        $counts = [];
        BodyInliner::walkOwnScope($nodes, static function (Node $node) use (&$counts): void {
            $targets = match (true) {
                $node instanceof Node\Expr\Assign, $node instanceof Node\Expr\AssignRef, $node instanceof Node\Expr\AssignOp => [$node->var],
                $node instanceof Node\Stmt\Foreach_ => [$node->keyVar, $node->valueVar],
                $node instanceof Node\Stmt\Unset_ => $node->vars,
                $node instanceof Node\Stmt\Global_ => $node->vars,
                default => [],
            };
            foreach ($targets as $target) {
                if ($target instanceof Node\Expr\Variable && is_string($target->name)) {
                    $counts[$target->name] = ($counts[$target->name] ?? 0) + 1;
                } elseif ($target instanceof Node\Expr\List_ || $target instanceof Node\Expr\Array_) {
                    foreach ($target->items as $item) {
                        if ($item?->value instanceof Node\Expr\Variable && is_string($item->value->name)) {
                            $counts[$item->value->name] = ($counts[$item->value->name] ?? 0) + 2;
                        }
                    }
                }
            }
        });

        return $counts;
    }
}
