<?php

namespace Recombinator;

use PhpParser\Node;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * Класс для упрощения прохода по AST дереву
 */
class Fluent
{
    protected $ast = [];
    protected $visitors = [];

    public function __construct(array $ast)
    {
        $this->ast = $ast;
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

        $traverser->addVisitor(new class extends NodeVisitorAbstract {

            public function enterNode(Node $node)
            {
//                if ($node instanceof Include_) {
//                    // включить в файл, предварительно рекурсивно обработав
//                }
//                if ($node instanceof Function_) {
//                    $fVisitor = new FunctionVisitor();
//                    $this->buffer[$node->name] = $fVisitor->enterNode($node);
//                }
//        echo (new NodeDumper)->dump($node) . "\n";die();
            }

            public function leaveNode(Node $node)
            {
                // не PHP вырезаем
                if ($node instanceof Node\Stmt\InlineHTML) {
                    return NodeTraverser::REMOVE_NODE;
                }

                // TODO отправлять на подгрузку в рунтайм
                if ($node instanceof Function_) {
                    return NodeTraverser::REMOVE_NODE;
                }
                if ($node instanceof Expression) {
                    if ($node->expr instanceof Node\Expr\Include_) {
                        return NodeTraverser::REMOVE_NODE;
                    }
                }

//                // если пользовательская функция - заменяем на тело из буфера
//                if ($node instanceof FuncCall) {
////            return NodeTraverser::REMOVE_NODE;
//                    if (array_key_exists($node->name->getLast(), $this->buffer)) {
//                        return $this->buffer[$node->name->getLast()];
//                    }
//                }
            }
        });

        return $traverser->traverse($this->ast);
    }



    /**
     * Запрос к сокету - выполнить кусок кода
     */
    protected function runCode($code)
    {
        $socket = socket_create(AF_UNIX, SOCK_DGRAM, 0);
        socket_sendto($socket, "Hello World!", 12, 0, "/tmp/myserver.sock", 0);
        echo "sent\n";
    }

    /**
     * Запрос к сокету - получить тело функции / класса
     */
    protected function getDefinition($callName)
    {
        $socket = socket_create(AF_UNIX, SOCK_DGRAM, 0);
        socket_sendto($socket, "Hello World!", 12, 0, "/tmp/myserver.sock", 0);
        echo "sent\n";
    }
}