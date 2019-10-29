<?php

namespace Recombinator\Visitor;

use PhpParser\Node;
use PhpParser\NodeDumper;
use PhpParser\Node\Expr\Variable;
use PhpParser\NodeVisitorAbstract;

class VariableVisitor extends NodeVisitorAbstract
{
    protected $params;

    public function __construct($params)
    {
        $this->params = $params;
    }

    /**
     * @param Variable $node
     */
    public function enterNode(Node $node)
    {
        // проходим по телу функции, заменяя параметры на новые идентификаторы
        if (key_exists($node->name, $this->params)) {
            $node->name = $this->params[$node->name];
            return $node;
        }
    }
}