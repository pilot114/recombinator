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
        $this->ast = $this->buildAST();

        $this->ast = (new Fluent($this->ast))
            ->withVisitors([
            ])
            ->modify();

//        $nodeFinder = new NodeFinder;
//        $assigns = $nodeFinder->findInstanceOf($this->ast, Assign::class);
//        $vars = array_map(function($x) { return $x->var->name; }, $assigns);

        // includes и определения отправляем на выполнение

        // TODO комментарии вырезаем ...

        // TODO на остальное применяем маппер эквивалентных преобразований ...
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
    public function dump()
    {
        return (new PrettyDumper())->dump($this->ast) . "\n";
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
}