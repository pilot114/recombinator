<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;

#[VisitorMeta('Подстановка аргументов вместо параметров [внутренний хелпер CallFunctionVisitor]')]
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
            if (is_int($i)) {
                if ($var->getAttribute('arg_variadic')) {
                    $items = [];
                    $counter = count($this->node->args);
                    for ($j = $i; $j < $counter; $j++) {
                        $arg = $this->node->args[$j];
                        if ($arg instanceof Node\Arg) {
                            $items[] = new Node\ArrayItem($arg->value);
                        }
                    }

                    return new Node\Expr\Array_($items);
                }

                if (isset($this->node->args[$i])) {
                    $arg = $this->node->args[$i];
                    if ($arg instanceof Node\Arg) {
                        return $arg->value;
                    }
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
