<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;
use Recombinator\Transformation\BodyInliner;

/**
 * Встраивает немедленный вызов замыкания-литерала:
 *
 *   $entry = \call_user_func(static function ($c) { $s = $c->get('x'); return f($s); }, $cnt);
 *   $entry = (function ($c) { ... })($cnt);
 *   →
 *   $closure_s_1 = $cnt->get('x');
 *   $entry = f($closure_s_1);
 *
 * Условия: вызов — отдельный оператор или правая часть присваивания переменной;
 * у замыкания нет захвата по ссылке; return только последним оператором тела.
 * Захваченные по значению переменные читаются напрямую, а если тело их меняет —
 * через копию.
 */
#[VisitorMeta('Встраивание вызова замыкания: call_user_func(function..., $x) → тело', targets: [Node\Expr\Closure::class])]
class ClosureCallInlinerVisitor extends BaseVisitor
{
    /** @var array<string, true> */
    private array $usedVars = [];

    #[\Override]
    public function beforeTraverse(array $nodes): ?array
    {
        parent::beforeTraverse($nodes);
        $this->usedVars = [];
        foreach ($this->findNode(Node\Expr\Variable::class) as $var) {
            if (is_string($var->name)) {
                $this->usedVars[$var->name] = true;
            }
        }

        return null;
    }

    public function enterNode(Node $node): ?Node
    {
        if (!$node instanceof Node\Stmt\Expression) {
            return null;
        }

        $target = null;
        $call   = $node->expr;
        if ($call instanceof Node\Expr\Assign && $call->var instanceof Node\Expr\Variable && is_string($call->var->name)) {
            $target = $call->var;
            $call   = $call->expr;
        }

        [$closure, $args] = $this->closureCall($call) ?? [null, []];
        if (!$closure instanceof Node\Expr\Closure) {
            return null;
        }

        $stmts = $this->inlineClosure($closure, $args, $target);
        if ($stmts !== null) {
            $node->setAttribute('replace', $stmts);
        }

        return null;
    }

    /**
     * @return array{Node\Expr\Closure, array<Node\Arg|Node\VariadicPlaceholder>}|null
     */
    private function closureCall(Node\Expr $call): ?array
    {
        if (!$call instanceof Node\Expr\FuncCall) {
            return null;
        }

        // (function () {...})($x)
        if ($call->name instanceof Node\Expr\Closure) {
            return [$call->name, $call->args];
        }

        // call_user_func(function () {...}, $x)
        if (
            $call->name instanceof Node\Name
            && strtolower($call->name->getLast()) === 'call_user_func'
            && ($call->args[0] ?? null) instanceof Node\Arg
            && $call->args[0]->value instanceof Node\Expr\Closure
            && !$call->args[0]->unpack
        ) {
            return [$call->args[0]->value, array_slice($call->args, 1)];
        }

        return null;
    }

    /**
     * @param  array<Node\Arg|Node\VariadicPlaceholder> $args
     * @return array<Node\Stmt>|null
     */
    private function inlineClosure(Node\Expr\Closure $closure, array $args, ?Node\Expr\Variable $target): ?array
    {
        if ($closure->byRef) {
            return null;
        }

        $body   = $closure->stmts;
        $result = null;
        $last   = end($body);
        if ($last instanceof Node\Stmt\Return_) {
            array_pop($body);
            $result = $last->expr;
        }

        // Значение результата — через локальную переменную тела, которая потом станет $target
        $resultVar = '__result';
        if ($result instanceof Node\Expr) {
            $body[] = new Node\Stmt\Expression(new Node\Expr\Assign(new Node\Expr\Variable($resultVar), $result));
        }

        // Лишние аргументы PHP вычисляет, но не связывает — встраивание бы их потеряло
        if (count($args) > count($closure->params)) {
            return null;
        }

        // Пропущенные параметры — значения по умолчанию; именованные аргументы с use не сопоставить надёжно
        $positional = [];
        foreach ($closure->params as $i => $param) {
            $arg = $args[$i] ?? null;
            if ($arg !== null && (!$arg instanceof Node\Arg || $arg->name instanceof Node\Identifier)) {
                return null;
            }

            $positional[] = $arg ?? new Node\Arg($param->default ?? new Node\Expr\ConstFetch(new Node\Name('null')));
        }

        // Захваченные переменные — как параметры с аргументами-переменными тех же имён
        $params = $closure->params;
        foreach ($closure->uses as $use) {
            if ($use->byRef || !is_string($use->var->name)) {
                return null;
            }

            $params[]     = new Node\Param(new Node\Expr\Variable($use->var->name));
            $positional[] = new Node\Arg(new Node\Expr\Variable($use->var->name));
        }

        $stmts   = BodyInliner::inline($params, $positional, $body, 'closure', null, '', $this->usedVars);
        if ($stmts === null) {
            return null;
        }

        if (!$result instanceof Node\Expr) {
            if ($target instanceof Node\Expr\Variable) {
                $stmts[] = new Node\Stmt\Expression(new Node\Expr\Assign($target, new Node\Expr\ConstFetch(new Node\Name('null'))));
            }

            return $stmts;
        }

        // Последний оператор — `$closure___result_N = <expr>`: присваиваем сразу в цель
        $assign = array_pop($stmts);
        if (!$assign instanceof Node\Stmt\Expression || !$assign->expr instanceof Node\Expr\Assign) {
            return null;
        }

        $stmts[] = $target instanceof Node\Expr\Variable
            ? new Node\Stmt\Expression(new Node\Expr\Assign($target, $assign->expr->expr))
            : new Node\Stmt\Expression($assign->expr->expr);

        return $stmts;
    }
}
