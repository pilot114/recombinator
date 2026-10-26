<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;
use Recombinator\Transformation\BodyInliner;

/**
 * Выносит создание объекта из аргумента вызова в отдельную переменную:
 *
 *   $cnt->register(new Doctrine());
 *   →
 *   $doctrine1 = new Doctrine();
 *   $cnt->register($doctrine1);
 *
 * Объект, созданный прямо в аргументе, не имеет имени, и правила, работающие
 * с `$var = new C()` (встраивание процедур, удаление класса), его не видят.
 *
 * Применяется только к вызову-оператору процедуры объекта с известным классом
 * (`$v = new C()` на верхнем уровне, метод без return) — именно его потом
 * развернёт ObjectMethodInlinerVisitor. В вызовах, возвращающих значение,
 * `new X(const)` оставляем: его как значение обрабатывают PreExecution и
 * инлайн конструкторов.
 *
 * Выносятся только объекты классов, объявленных в коде. Порядок вычисления
 * сохраняется: предыдущие аргументы должны быть без побочных эффектов.
 */
#[VisitorMeta('Вынос new X() из аргументов вызова в переменную')]
class NewArgHoistVisitor extends BaseVisitor
{
    /** @var array<string, Node\Stmt\Class_> lowercase имя → класс, объявленный в AST */
    private array $classes = [];

    /** @var array<string, Node\Stmt\Class_> переменная верхнего уровня → класс её объекта */
    private array $objects = [];

    /** @var array<string, true> имена переменных, занятые в коде */
    private array $usedVars = [];

    #[\Override]
    public function beforeTraverse(array $nodes): ?array
    {
        parent::beforeTraverse($nodes);
        $this->classes  = [];
        $this->usedVars = [];

        foreach ($this->findNode(Node\Stmt\Class_::class) as $class) {
            if ($class->name instanceof Node\Identifier && !$class->isAbstract()) {
                $this->classes[strtolower($class->name->toString())] = $class;
            }
        }

        $this->objects = [];
        $counts        = ObjectMethodInlinerVisitor::topLevelAssignCounts($nodes);
        foreach ($nodes as $stmt) {
            if (
                $stmt instanceof Node\Stmt\Expression
                && $stmt->expr instanceof Node\Expr\Assign
                && $stmt->expr->var instanceof Node\Expr\Variable
                && is_string($stmt->expr->var->name)
                && ($counts[$stmt->expr->var->name] ?? 0) === 1
                && $stmt->expr->expr instanceof Node\Expr\New_
                && $stmt->expr->expr->class instanceof Node\Name
                && isset($this->classes[strtolower($stmt->expr->expr->class->toString())])
            ) {
                $this->objects[$stmt->expr->var->name] = $this->classes[strtolower($stmt->expr->expr->class->toString())];
            }
        }

        foreach ($this->findNode(Node\Expr\Variable::class) as $var) {
            if (is_string($var->name)) {
                $this->usedVars[$var->name] = true;
            }
        }

        return null;
    }

    public function enterNode(Node $node): ?Node
    {
        if (!$node instanceof Node\Stmt\Expression || $this->classes === []) {
            return null;
        }

        $call = $node->expr;
        if (
            !$call instanceof Node\Expr\MethodCall
            || !$call->var instanceof Node\Expr\Variable
            || !is_string($call->var->name)
            || !isset($this->objects[$call->var->name])
            || !$call->name instanceof Node\Identifier
        ) {
            return null;
        }

        $method = $this->objects[$call->var->name]->getMethod($call->name->toString());
        if ($method?->stmts === null || !BodyInliner::isInlinableBody($method->stmts)) {
            return null;
        }

        $hoisted = [];
        foreach ($call->args as $arg) {
            if (!$arg instanceof Node\Arg || $arg->unpack || $arg->byRef) {
                break;
            }

            $value = $arg->value;
            if (
                $value instanceof Node\Expr\New_
                && $value->class instanceof Node\Name
                && isset($this->classes[strtolower($value->class->toString())])
                && array_all(
                    $value->args,
                    fn(Node\Arg|Node\VariadicPlaceholder|Node\ArgPlaceholder $a): bool => $a instanceof Node\Arg && $this->isSideEffectFree($a->value)
                )
            ) {
                $name       = $this->freshName($value->class->getLast());
                $hoisted[]  = new Node\Stmt\Expression(new Node\Expr\Assign(new Node\Expr\Variable($name), $value));
                $arg->value = new Node\Expr\Variable($name);
                continue;
            }

            // После аргумента с побочными эффектами следующие new выносить нельзя
            if (!$this->isSideEffectFree($value)) {
                break;
            }
        }

        if ($hoisted === []) {
            return null;
        }

        $node->setAttribute('hoistBefore', $hoisted);

        return null;
    }

    #[\Override]
    public function leaveNode(Node $node): int|Node|array|null
    {
        $hoisted = $node->getAttribute('hoistBefore');
        if (is_array($hoisted)) {
            // Атрибут снимаем: оператор остаётся в AST и не должен вставлять копии повторно
            $node->setAttribute('hoistBefore', null);
            return [...$hoisted, $node];
        }

        return parent::leaveNode($node);
    }

    private function isSideEffectFree(Node\Expr $expr): bool
    {
        return $expr instanceof Node\Expr\Variable || BodyInliner::isConstant($expr);
    }

    private function freshName(string $className): string
    {
        // Slim_Middleware_ContentLength → contentLength
        $parts = explode('_', $className);
        $base  = lcfirst(end($parts));
        for ($i = 1;; $i++) {
            if (!isset($this->usedVars[$base . $i])) {
                $this->usedVars[$base . $i] = true;
                return $base . $i;
            }
        }
    }
}
