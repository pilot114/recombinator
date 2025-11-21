<?php

declare(strict_types=1);

namespace Recombinator;

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;

/**
 * Класс для упрощения прохода по AST дереву
 */
class Fluent
{
    /** @var array<int, \PhpParser\Node> */
    protected array $ast = [];

    /** @var array<int, NodeVisitor> */
    protected array $visitors = [];

    /**
     * @param array<int, \PhpParser\Node> $ast
     */
    public function __construct(array $ast)
    {
        $this->ast = $ast;
    }

    /**
     * @param array<int, NodeVisitor> $visitors
     * @return $this
     */
    public function withVisitors(array $visitors): self
    {
        $this->visitors = array_merge($this->visitors, $visitors);
        return $this;
    }

    /**
     * @return array<int, \PhpParser\Node>
     */
    public function modify(): array
    {
        $traverser = new NodeTraverser();
        foreach ($this->visitors as $visitor) {
            $traverser->addVisitor($visitor);
        }
        return $traverser->traverse($this->ast);
    }
}