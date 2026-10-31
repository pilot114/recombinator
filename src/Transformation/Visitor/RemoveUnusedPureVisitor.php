<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;
use Recombinator\Analysis\PurityAnalyzer;
use Recombinator\Support\Sandbox;

/**
 * Удаляет чистые вычисления, результат которых нигде не используется:
 * 1. Statement-выражения без присваивания: `$a + $b;` → удалить
 * 2. Присваивания чистого выражения переменной, которая нигде не читается: `$x = $a * 2;` → удалить
 *
 * «Чистое» = без побочных эффектов: операции над скалярами/переменными,
 * а также вызовы заведомо чистых стандартных функций.
 */
#[VisitorMeta('Удаление мёртвых чистых вычислений', targets: [Node\Stmt\Expression::class])]
class RemoveUnusedPureVisitor extends BaseVisitor
{
    /**
     * Переменные, которые хотя бы раз читаются в коде (не только присваиваются)
     *
     * @var array<string, true>
     */
    private array $readVars = [];

    /** @var array<string, Node\Stmt\Class_>|null lowercase имя → класс, собирается при первой нужде */
    private ?array $classes = null;

    #[\Override]
    public function beforeTraverse(array $nodes): ?array
    {
        parent::beforeTraverse($nodes);
        $this->readVars = $this->collectReadVars($nodes);
        $this->classes  = null;
        return null;
    }

    public function enterNode(Node $node): int|Node|array|null
    {
        if (!$node instanceof Node\Stmt\Expression) {
            return null;
        }

        $expr = $node->expr;

        // Случай 1: чистое выражение как statement (результат выброшен, присваивания нет)
        if (!$expr instanceof Node\Expr\Assign && $this->isPure($expr)) {
            $node->setAttribute('remove', true);
            return null;
        }

        // Случай 2: присваивание чистого выражения переменной, которая нигде не читается
        if (
            $expr instanceof Node\Expr\Assign &&
            $expr->var instanceof Node\Expr\Variable &&
            is_string($expr->var->name) &&
            !isset($this->readVars[$expr->var->name]) &&
            $this->isPure($expr->expr)
        ) {
            $node->setAttribute('remove', true);
            return null;
        }

        // Случай 3: $x++ / ++$x / $x-- / --$x для переменной, которая нигде не читается
        if (
            ($expr instanceof Node\Expr\PostInc || $expr instanceof Node\Expr\PreInc
            || $expr instanceof Node\Expr\PostDec || $expr instanceof Node\Expr\PreDec)
            && $expr->var instanceof Node\Expr\Variable
            && is_string($expr->var->name)
            && !isset($this->readVars[$expr->var->name])
        ) {
            $node->setAttribute('remove', true);
            return null;
        }

        // Случай 4: $x += expr / $x .= expr для переменной, которая нигде не читается
        if (
            $expr instanceof Node\Expr\AssignOp
            && $expr->var instanceof Node\Expr\Variable
            && is_string($expr->var->name)
            && !isset($this->readVars[$expr->var->name])
            && $this->isPure($expr->expr)
        ) {
            $node->setAttribute('remove', true);
            return null;
        }

        return null;
    }

    private function isStatementLevel(Node $expr): bool
    {
        $parent = $expr->getAttribute('parent');

        return $parent instanceof Node\Stmt\Expression
            || $parent instanceof Node\Stmt\For_ && in_array($expr, [...$parent->init, ...$parent->loop], true);
    }

    /**
     * Собирает имена переменных, которые встречаются в позиции чтения
     * (всё кроме прямой левой части Assign).
     *
     * @param  array<Node> $nodes
     * @return array<string, true>
     */
    private function collectReadVars(array $nodes): array
    {
        $readVars = [];

        // Variable nodes that are mutation targets (not real reads)
        $mutationLhsIds = [];
        foreach ($this->findNode(Node\Expr\Assign::class, $nodes) as $assign) {
            if ($assign->var instanceof Node\Expr\Variable) {
                $mutationLhsIds[spl_object_id($assign->var)] = true;
            }
        }

        // `$x += 1;` и `$i++;` отдельным оператором — только запись; внутри выражения
        // (`bindValue($i++, ...)`) их значение читается
        foreach ($this->findNode(Node\Expr\AssignOp::class, $nodes) as $assignOp) {
            if ($assignOp->var instanceof Node\Expr\Variable && $this->isStatementLevel($assignOp)) {
                $mutationLhsIds[spl_object_id($assignOp->var)] = true;
            }
        }

        foreach (
            [
            Node\Expr\PostInc::class, Node\Expr\PreInc::class,
            Node\Expr\PostDec::class, Node\Expr\PreDec::class,
            ] as $cls
        ) {
            foreach ($this->findNode($cls, $nodes) as $mut) {
                if ($mut->var instanceof Node\Expr\Variable && $this->isStatementLevel($mut)) {
                    $mutationLhsIds[spl_object_id($mut->var)] = true;
                }
            }
        }

        foreach ($this->findNode(Node\Expr\Variable::class, $nodes) as $var) {
            if (is_string($var->name) && !isset($mutationLhsIds[spl_object_id($var)])) {
                $readVars[$var->name] = true;
            }
        }

        return $readVars;
    }

    private function isPureConstruction(string $className, int $depth): bool
    {
        $lower = strtolower(ltrim($className, '\\'));
        if (in_array($lower, PurityAnalyzer::PURE_BUILTIN_CLASSES, true)) {
            return true;
        }

        if ($this->classes === null) {
            $this->classes = [];
            foreach ($this->findNode(Node\Stmt\Class_::class) as $class) {
                if ($class->name instanceof Node\Identifier) {
                    $this->classes[strtolower($class->name->toString())] = $class;
                }
            }
        }

        $class = $this->classes[$lower] ?? null;
        if (!$class instanceof Node\Stmt\Class_ || $depth > 8 || $class->getMethod('__destruct') instanceof Node\Stmt\ClassMethod) {
            return false;
        }

        $ctor = $class->getMethod('__construct');
        if (!$ctor instanceof Node\Stmt\ClassMethod) {
            return !$class->extends instanceof Node\Name || $this->isPureConstruction($class->extends->toString(), $depth + 1);
        }

        // Тело — только записи в свои свойства чистыми выражениями
        return array_all($ctor->stmts ?? [], $this->isPureOwnPropWrite(...));
    }

    private function isPureOwnPropWrite(Node\Stmt $stmt): bool
    {
        return $stmt instanceof Node\Stmt\Expression
            && $stmt->expr instanceof Node\Expr\Assign
            && $stmt->expr->var instanceof Node\Expr\PropertyFetch
            && $stmt->expr->var->var instanceof Node\Expr\Variable
            && $stmt->expr->var->var->name === 'this'
            && $this->isPure($stmt->expr->expr);
    }

    private function isPure(Node $node): bool
    {
        if ($node instanceof Node\Scalar) {
            return true;
        }

        if ($node instanceof Node\Expr\ConstFetch) {
            return true;
        }

        if ($node instanceof Node\Expr\Variable) {
            return true;
        }

        if ($node instanceof Node\Expr\BinaryOp) {
            return $this->isPure($node->left) && $this->isPure($node->right);
        }

        if ($node instanceof Node\Expr\UnaryOp || $node instanceof Node\Expr\BooleanNot) {
            return $this->isPure($node->expr);
        }

        if ($node instanceof Node\Expr\Cast) {
            return $this->isPure($node->expr);
        }

        if ($node instanceof Node\Expr\Array_) {
            foreach ($node->items as $item) {
                if ($item === null) {
                    continue;
                }

                if ($item->key !== null && !$this->isPure($item->key)) {
                    return false;
                }

                if (!$this->isPure($item->value)) {
                    return false;
                }
            }

            return true;
        }

        if ($node instanceof Node\Expr\ArrayDimFetch) {
            return $this->isPure($node->var) && (!$node->dim instanceof Node\Expr || $this->isPure($node->dim));
        }

        // Создание объекта удаляемо, только если конструктор не трогает ничего, кроме
        // своих свойств: `static::$registry[...]++` в конструкторе — побочный эффект
        if ($node instanceof Node\Expr\New_) {
            return $node->class instanceof Node\Name
                && array_all(
                    $node->args,
                    fn(Node\Arg|Node\VariadicPlaceholder|Node\ArgPlaceholder $arg): bool => $arg instanceof Node\Arg && $this->isPure($arg->value),
                )
                && $this->isPureConstruction($node->class->toString(), 0);
        }

        if ($node instanceof Node\Expr\Ternary) {
            return $this->isPure($node->cond)
                && (!$node->if instanceof Node\Expr || $this->isPure($node->if))
                && $this->isPure($node->else);
        }

        if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name && Sandbox::isEffectFree($node->name->toString())) {
            $params = new \ReflectionFunction($node->name->toString())->getParameters();
            foreach ($node->args as $i => $arg) {
                if (!$arg instanceof Node\Arg) {
                    continue;
                }

                $param = $arg->name instanceof Node\Identifier
                    ? array_find($params, static fn(\ReflectionParameter $p): bool => $p->getName() === $arg->name->toString())
                    : $params[$i] ?? null;
                // Callback (`array_map('printf', $a)`) выполняется вызовом: удалять можно, только если он сам чистый
                $pure = $param !== null && str_contains((string) $param->getType(), 'callable')
                    ? $arg->value instanceof Node\Scalar\String_ && Sandbox::isEffectFree($arg->value->value)
                    : $this->isPure($arg->value);
                if (!$pure) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }
}
