<?php

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;
use Recombinator\Domain\ScopeStore;

/**
 * Убираем константы классов
 * - подменяем использование внутри класса
 * - подменяем использование вне класса
 */
class ConstClassVisitor extends BaseVisitor
{
    public function __construct(protected \Recombinator\Domain\ScopeStore $scopeStore)
    {
    }

    public function enterNode(Node $node): int|Node|array|null
    {
        if ($node instanceof Node\Stmt\ClassConst) {
            $name = $node->consts[0]->name->name;
            $scalar = $node->consts[0]->value;
            $this->scopeStore->setConstToScope($name, $scalar);
            $this->scopeStore->setConstToGlobal($name, $scalar);
            $node->setAttribute('remove', true);


            return null;
        }

        if ($node instanceof Node\Expr\ClassConstFetch) {
            // In php-parser 5.x, use toString() method
            $className = $node->class instanceof Node\Name ? $node->class->toString() : null;

            if ($className === 'self') {
                $replace = $this->scopeStore->getConstFromScope($node->name->name);
                $node->setAttribute('replace', $replace);
            } else {
                $replace = $this->scopeStore->getConstFromGlobal($node->name->name);
                $node->setAttribute('replace', $replace);
            }
        }

        return null;
    }
}