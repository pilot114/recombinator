<?php

namespace Recombinator;

use PhpParser\Node;

/**
 * Класс для упрощения прохода по AST дереву.
 *
 * Цель - выполнять любые сложные модификации
 * кода через инстанс FluentAccessor
 *
 * $fluentAccessor = new FluentAccessor();
 * $nodes = $fluentAccessor
 *     ->setAST($nodes)
 *     ->onlyInScope()
 *     ->withVisitors([
 *         $functionVisitor,
 *         $variableVisitor,
 *     ])
 *     ->modify();
 */
class FluentAccessor
{
    protected $deep = true;

    public function setAST(array $nodes)
    {
        return $this;
    }

    /**
     * Проходить только по верхнему скопу, а не по всем нодам
     */
    public function onlyInScope()
    {
        $this->deep = false;
        return $this;
    }

    public function withVisitors(array $visitors)
    {
        return $this;
    }

    public function modify()
    {
        return [];
    }
}