<?php

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use Recombinator\Domain\ScopeStore;

/**
 * Вызовы конструктора и методов заменяем на инстанцирование переменных из скопа класса
 * и тело конструктора
 */
class ConstructorAndMethodsVisitor extends BaseVisitor
{
    public function __construct(protected \Recombinator\Domain\ScopeStore $scopeStore)
    {
    }

    public function enterNode(Node $node)
    {
        // если класс оптимизирован, выгружаем его в стор
        if ($node instanceof Node\Stmt\Class_) {
            $this->processClass($node);
        }

        // подмена использования класса на его слепок из стора
        if ($node instanceof Node\Expr\New_) {
            return $this->processNewInstance($node);
        }

        // обработка вызовов методов
        if ($node instanceof Node\Expr\MethodCall) {
            return $this->processMethodCall($node);
        }

        return null;
    }

    /**
     * Обработка определения класса - сохранение его структуры в ScopeStore
     */
    protected function processClass(Node\Stmt\Class_ $node): void
    {
        $methods = $this->findNode(Node\Stmt\ClassMethod::class, $node);
        $optimize = true;
        $optimizeMethods = [];
        $hasConstructor = false;

        foreach ($methods as $method) {
            // Проверяем простоту методов (однострочные или конструктор)
            if ($method->name->name === '__construct') {
                $hasConstructor = true;
                $optimizeMethods['__construct'] = $method;
            } elseif (count($method->stmts) === 1 && $method->stmts[0] instanceof Node\Stmt\Return_) {
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

            // Обработка наследования
            $parentClass = null;
            if ($node->extends instanceof \PhpParser\Node\Name) {
                $parentClass = $node->extends->toString();
            }

            $this->scopeStore->setClassToGlobal(
                $node->name->name, [
                'props' => $optimizeProperties,
                'methods' => $optimizeMethods,
                'instances' => [],
                'parent' => $parentClass,
                ]
            );
        }
    }

    /**
     * Обработка создания нового экземпляра класса
     */
    protected function processNewInstance(Node\Expr\New_ $node): ?array
    {
        $assign = $node->getAttribute('parent');
        if (!$assign instanceof Node\Expr\Assign) {
            return null;
        }

        $uid = $this->buildUidByNode($node);
        $className = $node->class->toString();
        $class = $this->scopeStore->getClassFromGlobal($className);

        if ($class === null) {
            return null;
        }

        $instanceValue = [
            'name' => $assign->var->name,
            'properties' => [],
        ];
        $stmts = [];

        // Инициализация свойств значениями по умолчанию
        foreach ($class['props'] as $prop) {
            $varName = sprintf('%s_%s_%s', $assign->var->name, $prop->name->name, $uid);
            $var = new Node\Expr\Variable($varName);
            $stmt = new Node\Expr\Assign($var, $prop->default);
            $stmts[] = new Node\Stmt\Expression($stmt);

            $instanceValue['properties'][$prop->name->name] = $varName;
        }

        // Обработка конструктора, если есть
        if (isset($class['methods']['__construct'])) {
            $constructor = $class['methods']['__construct'];
            $constructorStmts = $this->inlineConstructor($constructor, $node, $instanceValue);
            $stmts = array_merge($stmts, $constructorStmts);
        }

        $class['instances'][] = $instanceValue;
        $this->scopeStore->setClassToGlobal($className, $class);

        // TODO: раскомментировать когда будет готова полная интеграция
        // $node->getAttribute('parent')->getAttribute('parent')->setAttribute('replace', $stmts);

        return null;
    }

    /**
     * Инлайнинг конструктора
     *
     * @return array<mixed>
     */
    protected function inlineConstructor(Node\Stmt\ClassMethod $constructor, Node\Expr\New_ $newExpr, array $instance): array
    {
        $stmts = [];

        foreach ($constructor->stmts as $stmt) {
            // Клонируем statement и заменяем $this->property на переменные инстанса
            $clonedStmt = clone $stmt;
            $traverser = new NodeTraverser();
            $traverser->addVisitor(new ThisPropertyReplacer($instance));
            $traverser->addVisitor(new ParametersToArgsVisitor($newExpr));
            $result = $traverser->traverse([$clonedStmt]);
            $stmts = array_merge($stmts, $result);
        }

        return $stmts;
    }

    /**
     * Обработка вызова метода
     */
    protected function processMethodCall(Node\Expr\MethodCall $node): ?Node
    {
        // Поддержка цепочки вызовов: $obj->method1()->method2()
        $var = $node->var;

        // Если это цепочка вызовов, сначала обрабатываем внутренний вызов
        if ($var instanceof Node\Expr\MethodCall) {
            $innerResult = $this->processMethodCall($var);
            if ($innerResult instanceof \PhpParser\Node) {
                $var = $innerResult;
            }
        }

        // Получаем имя объекта
        if ($var instanceof Node\Expr\Variable) {
            $instanceName = $var->name;
        } else {
            return null;
        }

        $methodName = $node->name->name;
        $result = $this->scopeStore->findClassNameAndInstance($instanceName);

        if ($result === null) {
            return null;
        }

        [$className, $instance] = $result;
        $class = $this->scopeStore->getClassFromGlobal($className);

        if ($class === null) {
            return null;
        }

        // Ищем метод в классе
        if (isset($class['methods'][$methodName])) {
            $method = $class['methods'][$methodName];
            return $this->replaceCallToBody($node, $method, $instance);
        }

        // Если метод не найден, проверяем родительский класс
        if ($class['parent'] !== null) {
            $parentClass = $this->scopeStore->getClassFromGlobal($class['parent']);
            if ($parentClass !== null && isset($parentClass['methods'][$methodName])) {
                $method = $parentClass['methods'][$methodName];
                return $this->replaceCallToBody($node, $method, $instance);
            }
        }

        return null;
    }

    protected function replaceCallToBody(Node\Expr\MethodCall $call, Node\Stmt\ClassMethod $method, array $instance): ?\PhpParser\Node\Expr
    {
        if (count($method->stmts) === 1 && $method->stmts[0] instanceof Node\Stmt\Return_) {
            // Заменяем в копии тела параметры на аргументы и $this->property на значения
            $traverser = new NodeTraverser();
            $traverser->addVisitor(new ThisPropertyReplacer($instance));
            $traverser->addVisitor(new ParametersToArgsVisitor($call));
            $result = $traverser->traverse([clone $method->stmts[0]]);

            // Возвращаем выражение из return (без самого return)
            if ($result[0] instanceof Node\Stmt\Return_ && $result[0]->expr instanceof \PhpParser\Node\Expr) {
                return $result[0]->expr;
            }
        }

        return null;
    }
}