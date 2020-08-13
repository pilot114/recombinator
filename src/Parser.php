<?php

namespace Recombinator;

use PhpParser\NodeFinder;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;
use PhpParser\Error;

class Parser
{
    protected $entryPoint;
    protected $code;
    protected $ast;
    protected $scopes;

    public function __construct($path)
    {
        $this->entryPoint = realpath($path);
        $this->code = file_get_contents($this->entryPoint);
    }

    /**
     * Запускает парсинг и начинает строить дерево скопов c заданным уровнем вложенности
     */
    public function parseScope()
    {
        $equal = new EqualVisitor();
        // для доступа к $node->getAttribute('parent')
        $parent = new ParentConnectingVisitor();

        $this->ast = $this->buildAST();
        $this->ast = (new Fluent($this->ast))
            ->withVisitors([$equal, $parent])
            ->modify();
    }

    /**
     * Подменяет код, используя дерево скопов
     */
    public function collapseScope()
    {
        
    }

    /**
     * Выводит результирующий код
     * @return string
     */
    public function prettyPrint()
    {
        return (new StandardPrinter)->prettyPrint($this->ast) . "\n";
    }

    /**
     * Выводит AST дерево, используя кастомный дампер
     * @return string
     */
    public function dumpAST()
    {
        return (new PrettyDumper())->dump($this->ast) . "\n";
    }

    /**
     * упрощенный способ найти узлы определенного типа
     *
     * Пример:
     * $assigns = $p->findNodeByClass(Assign::class);
     * $vars = array_map(function($x) { return $x->var->name; }, $assigns);
     */
    public function findNode($className)
    {
        $nodeFinder = new NodeFinder();
        return $nodeFinder->findInstanceOf($this->ast, $className);
    }

    /**
     * @return \PhpParser\Node[]|null
     */
    protected function buildAST()
    {
        $parser = (new ParserFactory)->create(ParserFactory::PREFER_PHP7);
        try {
            $this->ast = $parser->parse($this->code);
        } catch (Error $error) {
            echo "Parse error: {$error->getMessage()}\n";
            exit;
        }
        return $this->ast;
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