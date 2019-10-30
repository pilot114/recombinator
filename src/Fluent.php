<?php

namespace Recombinator;

use PhpParser\Node;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Expr\Include_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

use Recombinator\Visitor\FunctionVisitor;

/**
 * Класс для упрощения прохода по AST дереву
 */
class Fluent
{
    protected $nodes = [];
    protected $visitors = [];
    protected $buffer = [];

    public function __construct(array $nodes)
    {
        $this->nodes = $nodes;
        return $this;
    }

    public function withVisitors(array $visitors)
    {
        $this->visitors = array_merge($this->visitors, $visitors);
        return $this;
    }

    public function modify()
    {
        $traverser = new NodeTraverser();
        foreach ($this->visitors as $visitor) {
            $visitor->setBuffer($this->buffer);
            $traverser->addVisitor($visitor);
        }

        $traverser->addVisitor(new class extends NodeVisitorAbstract {

            // буфер функций
            public $buffer = [];

            public function enterNode(Node $node)
            {
                if ($node instanceof Include_) {
                    // включить в файл, предварительно рекурсивно обработав
                }
                if ($node instanceof Function_) {
                    $fVisitor = new FunctionVisitor();
                    $this->buffer[$node->name] = $fVisitor->enterNode($node);
                }
//        echo (new NodeDumper)->dump($node) . "\n";die();
            }

            public function leaveNode(Node $node)
            {
                // удаляем объявление функции
                if ($node instanceof Function_) {
                    return NodeTraverser::REMOVE_NODE;
                }

                // если пользовательская функция - заменяем на тело из буфера
                if ($node instanceof FuncCall) {
//            return NodeTraverser::REMOVE_NODE;
                    if (array_key_exists($node->name->getLast(), $this->buffer)) {
                        return $this->buffer[$node->name->getLast()];
                    }
                }
            }
        });
        return $traverser->traverse($this->nodes);
    }
}