<?php

namespace Recombinator;

use PhpParser\NodeTraverser;

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
        // TODO: перед модификацией нужно делать файловый кэш со скопами - так удобнее дебажить
        // TODO файловый кэш также нужно обновлять после каждого цикла

        $traverser = new NodeTraverser();
        foreach ($this->visitors as $visitor) {
            $traverser->addVisitor($visitor);
        }
        // признак модификации AST, используется при циклической замене ("меняем, пока это получается")
        // т.к. visitor не может писать в состояние traverser, используем обходной вариант
        do {
            $GLOBALS['modify_count'] = 0;
            $this->ast = $traverser->traverse($this->ast);
        } while ($GLOBALS['modify_count'] > 0);
        return $this->ast;
    }
}