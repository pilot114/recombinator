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
    public function __construct(protected \Recombinator\Domain\ScopeStore $scopeStore)
    {
    }

    public function enterNode(Node $node)
    {
        // Check if name is a Node\Name
        if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name) {
            // In php-parser 5.x, use toString() or $name property
            $funcName = $node->name->toString();
            $expr = $this->scopeStore->getFunctionFromGlobal($funcName);
            if ($expr) {
                // заменяем в копии тела параметры на аргументы
                $clonedExpr = clone $expr;

                // If there are arguments, replace parameters with arguments
                if ($node->args !== []) {
                    $traverser = new NodeTraverser();
                    $traverser->addVisitor(new ParametersToArgsVisitor($node));
                    $result = $traverser->traverse([$clonedExpr]);
                    return $result[0];
                }

                return $clonedExpr;
            }
        }
    }
}
