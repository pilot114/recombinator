<?php

namespace Recombinator\Visitor;

use PhpParser\Node;
use Recombinator\ScopeStore;

/**
 * Вызовы конструктора заменяем на инстанцирование переменных из скопа класса
 * и тело конструктора
 */
class ConstructorVisitor extends BaseVisitor
{
    protected $scopeStore;

    public function __construct(ScopeStore $scopeStore)
    {
        $this->scopeStore = $scopeStore;
    }

    public function enterNode(Node $node)
    {
        // если класс оптимизирован, выгружаем его в стор
        if ($node instanceof Node\Stmt\Class_) {
            $methods = $this->findNode(Node\Stmt\ClassMethod::class, $node);
            $optimize = true;
            $optimizeMethods = [];
            foreach ($methods as $method) {
                if (count($method->stmts) === 1) {
                    $optimizeMethods[$method->name->name] = $method->stmts[0];
                } else {
                    $optimize = false;
                }
            }
            if ($optimize) {
                $properties = $this->findNode(Node\Stmt\Property::class, $node);
                $optimizeProperties = [];
                foreach ($properties as $property) {
                    $optimizeProperties[$property->props[0]->name->name] = $property->props[0];
                }
                $this->scopeStore->setClassToGlobal($node->name, [
                    'props' => $optimizeProperties,
                    'methods' => $optimizeMethods,
                ]);
            }
        }

        // подмена использования класса на его слепок из стора
        if ($node instanceof Node\Expr\New_) {
            // TODO
            $this->debug($node);
        }
    }
}