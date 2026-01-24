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

    public function enterNode(Node $var): int|Node|array|null
    {
        if ($var instanceof Node\Expr\Variable) {
            $i = $var->getAttribute('arg_index');
            if (is_int($i) && isset($this->node->args[$i])) {
                $arg = $this->node->args[$i];
                if ($arg instanceof Node\Arg) {
                    return $arg->value;
                }
            }

            $default = $var->getAttribute('arg_default');
            if ($default instanceof Node) {
                return $default;
            }
        }

        return null;
    }
}
