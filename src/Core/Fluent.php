<?php

declare(strict_types=1);

namespace Recombinator\Core;

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;

/**
 * Класс для упрощения работы с AST через цепочку visitor'ов
 *
 * Предоставляет fluent interface для применения множества visitor'ов
 * к AST дереву за один проход, что упрощает трансформацию кода.
 *
 * Пример использования:
 * ```php
 * $ast = $parser->parse($code);
 * $fluent = new Fluent($ast);
 * $modifiedAst = $fluent
 *     ->withVisitors([new RemoveCommentsVisitor()])
 *     ->withVisitors([new OptimizeVisitor()])
 *     ->modify();
 * ```
 */
class Fluent
{
    /**
     *
     *
     * @var array<int, NodeVisitor>
     */
    protected array $visitors = [];

    /**
     * @param array<int, \PhpParser\Node> $ast
     */
    public function __construct(protected array $ast)
    {
    }

    /**
     * @param  array<int, NodeVisitor> $visitors
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

        $result = $traverser->traverse($this->ast);
        return array_values($result);
    }
}
