<?php

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;

/**
 * Заменяет $this->property на переменные инстанса
 * Используется при инлайнинге методов и конструкторов
 */
class ThisPropertyReplacer extends BaseVisitor
{
    /**
     * @param array<string, mixed> $instance
     */
    public function __construct(protected array $instance)
    {
    }

    public function enterNode(Node $node): ?Node
    {
        // Замена $this->property на переменную инстанса
        if ($node instanceof Node\Expr\PropertyFetch && ($node->var instanceof Node\Expr\Variable && $node->var->name === 'this')) {
            $name = $node->name;
            if ($name instanceof Node\Identifier) {
                $propertyName = $name->name;
                // Если это обращение для чтения
                $properties = $this->instance['properties'] ?? [];
                if (is_array($properties) && isset($properties[$propertyName])) {
                    $varName = $properties[$propertyName];
                    if (is_string($varName)) {
                        return new Node\Expr\Variable($varName);
                    }
                }
            }
        }

        // Замена присваивания $this->property = value
        if (
            $node instanceof Node\Expr\Assign
            && $node->var instanceof Node\Expr\PropertyFetch
            && $node->var->var instanceof Node\Expr\Variable
            && $node->var->var->name === 'this'
        ) {
            $name = $node->var->name;
            if ($name instanceof Node\Identifier) {
                $propertyName = $name->name;
                $properties = $this->instance['properties'] ?? [];
                if (is_array($properties) && isset($properties[$propertyName])) {
                    $varName = $properties[$propertyName];
                    if (is_string($varName)) {
                        return new Node\Expr\Assign(
                            new Node\Expr\Variable($varName),
                            $node->expr
                        );
                    }
                }
            }
        }

        return null;
    }
}
