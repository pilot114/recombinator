<?php

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;

/**
 * Заменяет $this->property на переменные инстанса
 * Используется при инлайнинге методов и конструкторов
 */
class ThisPropertyReplacer extends BaseVisitor
{
    public function __construct(protected array $instance)
    {
    }

    public function enterNode(Node $node)
    {
        // Замена $this->property на переменную инстанса
        if ($node instanceof Node\Expr\PropertyFetch && ($node->var instanceof Node\Expr\Variable && $node->var->name === 'this')) {
            $propertyName = $node->name->name;
            // Если это обращение для чтения
            if (isset($this->instance['properties'][$propertyName])) {
                $varName = $this->instance['properties'][$propertyName];
                return new Node\Expr\Variable($varName);
            }
        }

        // Замена присваивания $this->property = value
        if ($node instanceof Node\Expr\Assign && ($node->var instanceof Node\Expr\PropertyFetch && $node->var->var instanceof Node\Expr\Variable && $node->var->var->name === 'this')) {
            $propertyName = $node->var->name->name;
            if (isset($this->instance['properties'][$propertyName])) {
                $varName = $this->instance['properties'][$propertyName];
                return new Node\Expr\Assign(
                    new Node\Expr\Variable($varName),
                    $node->expr
                );
            }
        }

        return null;
    }
}
