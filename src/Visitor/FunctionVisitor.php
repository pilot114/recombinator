<?php

namespace Recombinator\Visitor;

use PhpParser\Node;
use PhpParser\Node\Expr\Variable;
use PhpParser\NodeVisitorAbstract;
use Recombinator\NameHasher;

class FunctionVisitor extends NodeVisitorAbstract
{
    public function enterNode(Node $node) {
        // переименовываем все использования аргументов в функции через NameHasher,
        // затем новое тело функции ложим в буфер, чтобы потом подставлять
        // по месту использования
        $params = [];
        foreach ($node->params as $param) {
            $params[$param->name] = NameHasher::hash($param->name, $node->name);
        }
        foreach ($node->params as $param) {
            $params[$param->name] = NameHasher::hash($param->name, $node->name);
        }

        // проходим по телу функции, заменяя параметры на новые идентификаторы
        if ($node instanceof Variable) {
            if (key_exists($node->name, $this->params)) {
                $node->setAttribute('params', )
                $node->name = $this->params[$node->name];
            }
        }
    }
}