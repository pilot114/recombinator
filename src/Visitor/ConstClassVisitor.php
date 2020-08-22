<?php

namespace Recombinator\Visitor;

use PhpParser\Node;
use Recombinator\ScopeStore;

/**
 * Убираем константы классов
 * - подменяем использование внутри класса
 * - подменяем использование вне класса
 */
class ConstClassVisitor extends BaseVisitor
{
    protected $scopeStore;

    public function __construct(ScopeStore $scopeStore)
    {
        $this->scopeStore = $scopeStore;
    }

    public function enterNode(Node $node)
    {
        if ($node instanceof Node\Stmt\ClassConst) {
            $this->debug($node);
        }

        if ($node instanceof Node\Expr\ClassConstFetch) {
            $this->debug($node);
        }
    }
}