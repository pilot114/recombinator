<?php

namespace Recombinator\Visitor;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use Recombinator\ScopeStore;

/**
 * Вызовы конструктора и методов заменяем на инстанцирование переменных из скопа класса
 * и тело конструктора
 */
class ConstructorAndMethodsVisitor extends BaseVisitor
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
                $this->scopeStore->setClassToGlobal($node->name->name, [
                    'props' => $optimizeProperties,
                    'methods' => $optimizeMethods,
                ]);
            }
        }

        // подмена использования класса на его слепок из стора
        if ($node instanceof Node\Expr\New_) {
            $className = $node->class->parts[0];
            ['props' => $props, 'methods' => $methods] = $this->scopeStore->getClassFromGlobal($className);
            $stmts = [];
            foreach ($props as $prop) {
                $varName = sprintf('%s_%s_%s', $className, $prop->name->name, $this->buildUidByNode($node));
                $var = new Node\Expr\Variable($varName);
                $stmt = new Node\Expr\Assign($var, $prop->default);
                $stmts[] = new Node\Stmt\Expression($stmt);
            }
            $node->getAttribute('parent')->getAttribute('parent')->setAttribute('replace', $stmts);
            // с методами вроде ничего не делаем
//            foreach ($methods as $method) {
//                $this->debug($method);
//            }
        }

        if ($node instanceof Node\Expr\MethodCall) {
            $this->debug($node);
            var_dump($node->name->name);

//            $expr = $this->scopeStore->getFunctionFromGlobal($node->name->parts[0]);
//            if ($expr) {
//                // заменяем в копии тела параметры на аргументы
//                $traverser = new NodeTraverser();
//                $traverser->addVisitor(new ParametersToArgsVisitor($node));
//                $expr = $traverser->traverse([clone $expr]);
//                return $expr[0];
//            }
        }
    }
}