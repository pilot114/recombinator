<?php

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;

class ParametersToArgsVisitor extends BaseVisitor
{
    /**
     * @param mixed $node
     */
    public function __construct(protected $node)
    {
    }

    public function enterNode(Node $var)
    {
        if ($var instanceof Node\Expr\Variable) {
            $i = $var->getAttribute('arg_index');
            if (isset($this->node->args[$i])) {
                return $this->node->args[$i]->value;
            }

            return $var->getAttribute('arg_default');
        }
    }
}
