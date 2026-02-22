<?php

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;
use Recombinator\Domain\ScopeStore;

/**
 * Замена обращений к свойствам объектов их значениями
 */
#[VisitorMeta('Замена $obj->property на значения из ScopeStore')]
class PropertyAccessVisitor extends BaseVisitor
{
    protected ScopeStore $scopeStore;

    public function __construct(?ScopeStore $scopeStore = null)
    {
        $this->scopeStore = $scopeStore ?? ScopeStore::default();
    }

    public function enterNode(Node $node)
    {
        // Обработка $object->property
        // Получаем имя объекта
        if ($node instanceof Node\Expr\PropertyFetch && $node->var instanceof Node\Expr\Variable) {
            $instanceName = $node->var->name;
            $propertyName = $node->name->name;
            // Ищем класс и инстанс объекта
            $result = $this->scopeStore->findClassNameAndInstance($instanceName);
            if ($result !== null) {
                [$className, $instance] = $result;

                // Проверяем, есть ли такое свойство в инстансе
                if (isset($instance['properties'][$propertyName])) {
                    // Заменяем на переменную, содержащую значение свойства
                    $varName = $instance['properties'][$propertyName];
                    return new Node\Expr\Variable($varName);
                }
            }
        }

        // Обработка $this->property внутри методов
        if (
            $node instanceof Node\Expr\PropertyFetch
            && $node->var instanceof Node\Expr\Variable
            && $node->var->name === 'this'
        ) {
            // Это обработаем позже, когда будем инлайнить методы
            // Нужно знать контекст текущего объекта
        }

        return null;
    }
}
