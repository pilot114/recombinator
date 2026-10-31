<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;
use Recombinator\Domain\ScopeStore;

/**
 * Собирает тела однострочных функций в ScopeStore, чтобы CallFunctionVisitor мог
 * инлайнить вызовы. Определение остаётся: его удалит RemoveUnusedFunctionVisitor,
 * когда вызовов не останется (не каждый вызов удаётся встроить).
 *
 * Функция собирается, только если подстановка тела не меняет смысла:
 * - тело — один `return` без замыканий, `global`, func_get_args() и переменных
 *   кроме параметров (иначе они стали бы переменными вызывающего кода);
 * - параметры без ссылок, вариативности и скалярных типов (приведение `int $x`
 *   при вызове пропало бы), тип возврата не скалярный;
 * - функция не вызывает себя прямо или через другие собранные функции — иначе
 *   подстановка не закончилась бы.
 * Несобранная функция стирается из ScopeStore: тело из прошлого прохода устарело.
 */
#[VisitorMeta('Сбор однострочных функций в ScopeStore для встраивания вызовов', targets: [Node\Stmt\Function_::class])]
class FunctionBodyCollectorVisitor extends BaseVisitor
{
    private const array SCALAR_TYPES = ['int', 'float', 'string', 'bool', 'false', 'true'];

    private const array SCOPE_FUNCTIONS = ['func_get_args', 'func_num_args', 'func_get_arg', 'compact', 'extract', 'get_defined_vars'];

    private readonly ScopeStore $scopeStore;

    /** @var array<string, array{string, Node\Expr}> lowercase имя → [имя, собранное тело] */
    private array $collected = [];

    public function __construct(?ScopeStore $scopeStore = null)
    {
        $this->scopeStore = $scopeStore ?? ScopeStore::default();
    }

    #[\Override]
    public function beforeTraverse(array $nodes): ?array
    {
        parent::beforeTraverse($nodes);
        $this->collected = [];

        return null;
    }

    public function enterNode(Node $node): null
    {
        if (!($node instanceof Node\Stmt\Function_)) {
            return null;
        }

        $name = (string) $node->name;
        $body = $this->collect($node);
        $this->scopeStore->setFunctionToGlobal($name, $body);
        if ($body instanceof Node\Expr) {
            $this->collected[strtolower($name)] = [$name, $body];
        }

        return null;
    }

    /** Функции в цикле вызовов не встраиваются. */
    #[\Override]
    public function afterTraverse(array $nodes): ?array
    {
        $calls = [];
        foreach ($this->collected as $name => [, $body]) {
            foreach ($this->findNode(Node\Expr\FuncCall::class, $body) as $call) {
                $callee = $call->name instanceof Node\Name ? strtolower(ltrim($call->name->toString(), '\\')) : null;
                if ($callee !== null && isset($this->collected[$callee])) {
                    $calls[$name][] = $callee;
                }
            }
        }

        foreach ($this->collected as $name => [$original]) {
            if ($this->reaches($name, $name, $calls, [])) {
                $this->scopeStore->setFunctionToGlobal($original, null);
            }
        }

        return null;
    }

    private function collect(Node\Stmt\Function_ $function): ?Node\Expr
    {
        $stmts = $function->stmts;
        if (
            count($stmts) !== 1
            || !$stmts[0] instanceof Node\Stmt\Return_
            || !$stmts[0]->expr instanceof Node\Expr
            || $function->byRef
            || $this->hasScalarType($function->returnType)
        ) {
            return null;
        }

        $params = [];
        foreach ($function->params as $idx => $param) {
            if (
                $param->byRef || $param->variadic || $this->hasScalarType($param->type)
                || !$param->var instanceof Node\Expr\Variable || !is_string($param->var->name)
            ) {
                return null;
            }

            $params[$param->var->name] = [$idx, $param->default];
        }

        $body = $this->deepClone($stmts[0]->expr);
        assert($body instanceof Node\Expr);
        foreach ($this->findNode(Node::class, $body) as $node) {
            // Замыкание, запись в параметр (`return $x = 5`) — после подстановки это был бы
            // код вызывающей стороны
            if (
                $node instanceof Node\FunctionLike || $node instanceof Node\Expr\Assign || $node instanceof Node\Expr\AssignOp
                || $node instanceof Node\Expr\AssignRef || $node instanceof Node\Expr\PreInc || $node instanceof Node\Expr\PreDec
                || $node instanceof Node\Expr\PostInc || $node instanceof Node\Expr\PostDec
            ) {
                return null;
            }

            if (
                $node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name
                && in_array(strtolower($node->name->toString()), self::SCOPE_FUNCTIONS, true)
            ) {
                return null;
            }

            if (!$node instanceof Node\Expr\Variable) {
                continue;
            }

            if (!is_string($node->name) || !isset($params[$node->name])) {
                return null;
            }

            [$idx, $default] = $params[$node->name];
            $node->setAttribute('arg_index', $idx);
            $node->setAttribute('arg_default', $default);
            $node->setAttribute('arg_has_default', $default instanceof Node\Expr);
        }

        $body->setAttribute('param_count', count($params));

        return $body;
    }

    private function hasScalarType(?Node $type): bool
    {
        if (!$type instanceof Node) {
            return false;
        }

        $names = $type instanceof Node\Identifier ? [$type] : $this->findNode(Node\Identifier::class, $type);

        return array_any($names, static fn(Node\Identifier $id): bool => in_array($id->toLowerString(), self::SCALAR_TYPES, true));
    }

    /**
     * @param array<string, list<string>> $calls
     * @param array<string, true>         $seen
     */
    private function reaches(string $from, string $target, array $calls, array $seen): bool
    {
        foreach ($calls[$from] ?? [] as $callee) {
            if ($callee === $target) {
                return true;
            }

            if (!isset($seen[$callee]) && $this->reaches($callee, $target, $calls, $seen + [$callee => true])) {
                return true;
            }
        }

        return false;
    }
}
