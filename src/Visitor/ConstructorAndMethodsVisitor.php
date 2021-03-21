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
                    $optimizeMethods[$method->name->name] = $this->markupFunctionBody($method);
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
                    'instances' => [], // привязка инстансов к классу
                ]);
            }
        }

        // подмена использования класса на его слепок из стора
        if ($node instanceof Node\Expr\New_) {
            $assign = $node->getAttribute('parent');
            if (!$assign instanceof Node\Expr\Assign) {
                return;
            }

            $uid = $this->buildUidByNode($node);
            $className = $node->class->parts[0];
            $class = $this->scopeStore->getClassFromGlobal($className);

            $instanseValue = [
                'name' => $assign->var->name,
                'properties' => [],
            ];
            $stmts = [];
            foreach ($class['props'] as $prop) {
                $varName = sprintf('%s_%s_%s', $assign->var->name, $prop->name->name, $uid);
                $var = new Node\Expr\Variable($varName);
                $stmt = new Node\Expr\Assign($var, $prop->default);
                $stmts[] = new Node\Stmt\Expression($stmt);

                $instanseValue['properties'][$prop->name->name] = $varName;
            }
            $class['instances'][] = $instanseValue;
            $this->scopeStore->setClassToGlobal($className, $class);

//            $node->getAttribute('parent')->getAttribute('parent')->setAttribute('replace', $stmts);
        }

        if ($node instanceof Node\Expr\MethodCall) {
            $instanceName = $node->var->name;
            $methodName = $node->name->name;
            [$className, $instance] = $this->scopeStore->findClassNameAndInstance($instanceName);

            $class = $this->scopeStore->getClassFromGlobal($className);
            foreach ($class['methods'] as $iMethodName => $method) {
                if ($iMethodName === $methodName) {
                    return $this->replaceCallToBody($node, $method);
                }
            }
        }
    }

    protected function replaceCallToBody(Node\Expr\MethodCall $call, Node\Stmt\ClassMethod $method)
    {
        if (count($method->stmts) === 1 && $method->stmts[0] instanceof Node\Stmt\Return_) {
            foreach ($call->args as $i => $arg) {
                // TODO
                /**
                 * проблема - нужно пройти по $method->stmts[0] и заменить параметры на аргументы
                 * т.е. сделать прогон одного Visitor внутри другого
                 */
                $this->debug($call);
                $this->debug($method);
                die();
            }
        }
    }
}