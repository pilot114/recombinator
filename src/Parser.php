<?php

namespace Recombinator;

use PhpParser\Node\Expr\Assign;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;
use PhpParser\Error;
use PhpParser\NodeFinder;
use PhpParser\NodeDumper;

class Parser
{
    protected $code;
    protected $ast;

    public function __construct($code)
    {
        $this->code = $code;
    }

    public function parseScopeWithLevel($level)
    {
        $this->buildAST();

        $nodeFinder = new NodeFinder;
        $assigns = $nodeFinder->findInstanceOf($this->ast, Assign::class);

        // получить переменныe в текущем скопе
        $vars = array_map(function($x) { return $x->var; }, $assigns);
    }

    public function collapseScope()
    {
        
    }

    /**
     * Выводит AST дерево
     * @return string
     */
    public function dump()
    {
        return (new NodeDumper)->dump($this->ast) . "\n";
    }

    /**
     * Выводит код
     * @return string
     */
    public function print()
    {
        return (new StandardPrinter)->prettyPrint($this->ast) . "\n";
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

// использовать для модификации кода
//        $fluent = new Fluent($this->ast);
//        $this->ast = $fluent->modify();
}