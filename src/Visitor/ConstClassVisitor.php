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
            $name = $node->consts[0]->name->name;
            $scalar = $node->consts[0]->value;
            $this->scopeStore->setConstToScope($name, $scalar);
            $this->scopeStore->setConstToGlobal($name, $scalar);
            $node->setAttribute('remove', true);
        }

        if ($node instanceof Node\Expr\ClassConstFetch) {
            if ($node->class->parts[0] === 'self') {
                $replace = $this->scopeStore->getConstFromScope($node->name->name);
                $node->setAttribute('replace', $replace);
            } else {
                $replace = $this->scopeStore->getConstFromGlobal($node->name->name);
                $node->setAttribute('replace', $replace);
            }
        }
    }
}