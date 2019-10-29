<?php

namespace Recombinator\Visitor;

use PhpParser\Node;
use PhpParser\NodeDumper;
use PhpParser\Node\Expr\Variable;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Node\Stmt\Function_;
use Recombinator\NameHasher;

class FunctionVisitor extends NodeVisitorAbstract
{
    /**
     * @param Function_ $node
     */
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

        foreach ($node->getStmts() as $stmt) {
            var_dump($stmt);
            if ($stmt instanceof Variable) {
                echo (new NodeDumper)->dump($node) . "\n";die();
                $vVisitor = new VariableVisitor($params);
                $node = $vVisitor->enterNode($node);
                return $node;
            }
        }
    }
}