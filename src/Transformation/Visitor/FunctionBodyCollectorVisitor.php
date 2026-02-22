<?php

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;
use Recombinator\Domain\ScopeStore;

/**
 * Собирает тела однострочных функций в ScopeStore и удаляет определения,
 * чтобы CallFunctionVisitor мог инлайнить вызовы.
 *
 * Аналог FunctionScopeVisitor, но работает прямо в едином глобальном scope
 * (без разбивки по файлам).
 */
#[VisitorMeta('Сбор однострочных функций в ScopeStore · удаление их определений')]
class FunctionBodyCollectorVisitor extends BaseVisitor
{
    private readonly ScopeStore $scopeStore;

    public function __construct(?ScopeStore $scopeStore = null)
    {
        $this->scopeStore = $scopeStore ?? ScopeStore::default();
    }

    public function enterNode(Node $node): null
    {
        if (!($node instanceof Node\Stmt\Function_)) {
            return null;
        }

        $stmts = $node->stmts ?? [];
        if (count($stmts) !== 1 || !($stmts[0] instanceof Node\Stmt\Return_)) {
            return null;
        }

        $returnExpr = $stmts[0]->expr;
        if (!$returnExpr instanceof \PhpParser\Node\Expr) {
            return null;
        }

        // Map parameter names → argument index (needed by CallFunctionVisitor / ParametersToArgsVisitor)
        $params = [];
        foreach ($node->params as $idx => $param) {
            if ($param->var instanceof Node\Expr\Variable && is_string($param->var->name)) {
                $params[$param->var->name] = $idx;
            }
        }

        $body = clone $returnExpr;

        // Tag each Variable node in the body with its positional arg_index
        foreach ($this->findNode(Node\Expr\Variable::class, $body) as $var) {
            if ($var instanceof Node\Expr\Variable && is_string($var->name) && isset($params[$var->name])) {
                $var->setAttribute('arg_index', $params[$var->name]);
                $var->setAttribute('arg_default', null);
            }
        }

        $this->scopeStore->setFunctionToGlobal((string) $node->name, $body);
        $node->setAttribute('remove', true);

        return null;
    }
}
