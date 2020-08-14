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
        $traverser = new NodeTraverser();
        foreach ($this->visitors as $visitor) {
            $traverser->addVisitor($visitor);
        }
        return $traverser->traverse($this->ast);
    }
}