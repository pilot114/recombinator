<?php

namespace Recombinator\Visitor;

use PhpParser\Node;

class ParametersToArgsVisitor extends BaseVisitor
{
    protected $node;

    public function __construct($node)
    {
        $this->node = $node;
    }

    public function enterNode(Node $var)
    {
        if ($var instanceof Node\Expr\Variable) {
            $i = $var->getAttribute('arg_index');
            if (isset($this->node->args[$i])) {
                return $this->node->args[$i]->value;
            } else {
                return $var->getAttribute('arg_default');
            }
        }
    }
}