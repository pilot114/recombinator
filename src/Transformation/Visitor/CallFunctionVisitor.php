<?php

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use Recombinator\Domain\ScopeStore;

/**
 * Замена вызова функции телом функции
 */
#[VisitorMeta('Подстановка тел функций на место вызовов (инлайн)')]
class CallFunctionVisitor extends BaseVisitor
{
    protected ScopeStore $scopeStore;

    public function __construct(?ScopeStore $scopeStore = null)
    {
        $this->scopeStore = $scopeStore ?? ScopeStore::default();
    }

    public function enterNode(Node $node): ?Node
    {
        // Check if name is a Node\Name
        if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name) {
            // In php-parser 5.x, use toString() or $name property
            $funcName = $node->name->toString();
            $expr = $this->scopeStore->getFunctionFromGlobal($funcName);
            if ($expr instanceof Node\Expr) {
                // заменяем в копии тела параметры на аргументы
                $clonedExpr = clone $expr;

                // If there are arguments, replace parameters with arguments
                if ($node->args !== []) {
                    $traverser = new NodeTraverser();
                    $traverser->addVisitor(new ParametersToArgsVisitor($node));
                    $result = $traverser->traverse([$clonedExpr]);
                    $first = $result[0] ?? null;
                    return $first instanceof Node ? $first : null;
                }

                return $clonedExpr;
            }
        }

        return null;
    }
}
