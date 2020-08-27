<?php

namespace Recombinator\Visitor;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use Recombinator\ScopeStore;

/**
 * Замена вызова функции телом функции
 */
class CallFunctionVisitor extends BaseVisitor
{
    protected $scopeStore;

    public function __construct(ScopeStore $scopeStore)
    {
        $this->scopeStore = $scopeStore;
    }

    public function enterNode(Node $node)
    {
        if ($node instanceof Node\Expr\FuncCall) {
            $expr = $this->scopeStore->getFunctionFromGlobal($node->name->parts[0]);
            if ($expr) {
                // заменяем в копии тела параметры на аргументы
                $traverser = new NodeTraverser();
                $traverser->addVisitor(new ParametersToArgsVisitor($node));
                $expr = $traverser->traverse([clone $expr]);
                return $expr[0];
            }
        }
    }
}