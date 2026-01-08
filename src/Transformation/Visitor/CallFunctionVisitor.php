<?php

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use Recombinator\Domain\ScopeStore;

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
            // Check if name is a Node\Name and has parts
            if ($node->name instanceof Node\Name && !empty($node->name->parts)) {
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
}