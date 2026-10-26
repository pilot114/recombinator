<?php

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use Recombinator\Core\Config;
use Recombinator\Domain\ScopeStore;
use Recombinator\Transformation\BodyInliner;

/**
 * Вызовы конструктора и методов заменяем на инстанцирование переменных из скопа класса
 * и тело конструктора
 *
 * Объект разворачивается в переменные свойств, только если его переменная
 * присваивается в своей области один раз: у `$c = $c->inc()` в цикле каждая запись —
 * новый объект, а свойства всех копий должны остаться одной переменной.
 */
#[VisitorMeta('Инлайн конструкторов и методов: New_/MethodCall → тело из ScopeStore')]
class ConstructorAndMethodsVisitor extends BaseVisitor
{
    protected ScopeStore $scopeStore;

    protected Config $config;

    /** @var array<int, array<string, int>> id области → переменная → число присваиваний */
    private array $assignCounts = [];

    public function __construct(?ScopeStore $scopeStore = null, ?Config $config = null)
    {
        $this->scopeStore = $scopeStore ?? ScopeStore::default();
        $this->config = $config ?? new Config();
    }

    #[\Override]
    public function beforeTraverse(array $nodes): ?array
    {
        parent::beforeTraverse($nodes);
        $this->assignCounts = [];

        return null;
    }

    public function enterNode(Node $node): ?Node
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
        if (!$node->name instanceof Node\Identifier) {
            return; // анонимный класс — не инлайним
        }

        // Класс из публичного контракта не выгружаем в стор — значит он не
        // будет заинлайнен в местах использования, а его определение
        // сохранится для внешних потребителей.
        if ($this->config->isProtected($node->name->name)) {
            return;
        }

        $methods = $this->findNode(Node\Stmt\ClassMethod::class, $node);
        $optimize = true;
        $optimizeMethods = [];
        $hasConstructor = false;

        // Проверка на магические методы — не инлайним
        $dangerousMagicMethods = [
            '__destruct', '__clone', '__sleep', '__wakeup',
            '__serialize', '__unserialize', '__toString', '__debugInfo',
            '__get', '__set', '__isset', '__unset',
            '__call', '__callStatic', '__invoke',
        ];

        foreach ($methods as $method) {
            $methodName = $method->name->name;

            if (in_array($methodName, $dangerousMagicMethods, true)) {
                return;
            }

            $stmts = $method->stmts;
            // Проверяем простоту методов (однострочные или конструктор)
            if ($methodName === '__construct') {
                $hasConstructor = true;
                $optimizeMethods['__construct'] = $method;
            } elseif ($stmts !== null && count($stmts) === 1 && $stmts[0] instanceof Node\Stmt\Return_) {
                $optimizeMethods[$methodName] = $this->markupFunctionBody($method);
            } else {
                $optimize = false;
            }
        }

        if ($optimize) {
            $properties = $this->findNode(Node\Stmt\Property::class, $node);
            $optimizeProperties = [];
            foreach ($properties as $property) {
                if (($property->flags & \PhpParser\Modifiers::STATIC) !== 0) {
                    continue;
                }

                $prop = $property->props[0] ?? null;
                if ($prop instanceof Node\PropertyItem) {
                    $optimizeProperties[$prop->name->name] = $prop;
                }
            }

            // Handle promoted properties (PHP 8.0+)
            if (isset($optimizeMethods['__construct'])) {
                foreach ($optimizeMethods['__construct']->params as $param) {
                    if ($param->flags !== 0 && $param->var instanceof Node\Expr\Variable && is_string($param->var->name)) {
                        $propName = $param->var->name;
                        if (!isset($optimizeProperties[$propName])) {
                            $propItem = new Node\PropertyItem($propName, $param->default);
                            $optimizeProperties[$propName] = $propItem;
                        }
                    }
                }
            }

            // Обработка наследования
            $parentClass = null;
            if ($node->extends instanceof Node\Name) {
                $parentClass = $node->extends->toString();
            }

            $this->scopeStore->setClassToGlobal(
                $node->name->name,
                [
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

        if (!$assign->var instanceof Node\Expr\Variable || !is_string($assign->var->name)) {
            return null;
        }

        if (!$assign->getAttribute('parent') instanceof Node\Stmt\Expression || $this->assignCount($assign) !== 1) {
            return null;
        }

        // $node->class может быть Name, выражением (new $cls(), new static::$x())
        // или анонимным классом. Инлайнить можем только по статически известному имени.
        if (!$node->class instanceof Node\Name) {
            return null;
        }

        $uid = $this->buildUidByNode($node);
        $className = $node->class->toString();
        $class = $this->scopeStore->getClassFromGlobal($className);

        if ($class === null || !$this->canScalarize($assign, $class)) {
            return null;
        }

        $instanceValue = [
            'name' => $assign->var->name,
            'properties' => [],
        ];
        $stmts = [];

        // Карта promoted-свойств: propName → индекс аргумента конструктора
        $promotedArgIndex = [];
        if (isset($class['methods']['__construct'])) {
            foreach ($class['methods']['__construct']->params as $i => $param) {
                if (
                    $param->flags !== 0
                    && $param->var instanceof Node\Expr\Variable
                    && is_string($param->var->name)
                ) {
                    $promotedArgIndex[$param->var->name] = $i;
                }
            }
        }

        // Инициализация свойств: promoted → из args вызова, обычные → из default
        foreach ($class['props'] as $prop) {
            $propName = $prop->name->name;
            $varName = sprintf('%s_%s_%s', $assign->var->name, $propName, $uid);
            $var = new Node\Expr\Variable($varName);

            // Свойство без значения по умолчанию: нетипизированное равно null,
            // типизированное не инициализировано — чтение до записи всё равно ошибка
            $initExpr = $prop->default ?? new Node\Expr\ConstFetch(new Node\Name('null'));
            if (isset($promotedArgIndex[$propName])) {
                $i = $promotedArgIndex[$propName];
                if (isset($node->args[$i]) && $node->args[$i] instanceof Node\Arg) {
                    $initExpr = $node->args[$i]->value;
                } elseif (isset($class['methods']['__construct']->params[$i])) {
                    $param = $class['methods']['__construct']->params[$i];
                    if ($param->default instanceof Node\Expr) {
                        $initExpr = $param->default;
                    }
                }
            }

            $stmts[] = new Node\Stmt\Expression(new Node\Expr\Assign($var, $initExpr));
            $instanceValue['properties'][$propName] = $varName;
        }

        // Обработка тела конструктора (непромоутед-присваивания, прочая логика)
        if (isset($class['methods']['__construct'])) {
            $constructor = $class['methods']['__construct'];
            $constructorStmts = $this->inlineConstructor($constructor, $node, $instanceValue, $className);
            $stmts = array_merge($stmts, $constructorStmts);
        }

        $class['instances'][] = $instanceValue;
        $this->scopeStore->setClassToGlobal($className, $class);

        $parentStmt = $node->getAttribute('parent');
        if ($parentStmt instanceof Node) {
            $grandparent = $parentStmt->getAttribute('parent');
            if ($grandparent instanceof Node) {
                $grandparent->setAttribute('replace', $stmts);
            }
        }

        return null;
    }

    /**
     * Инлайнинг конструктора
     *
     * @return array<mixed>
     */
    protected function inlineConstructor(Node\Stmt\ClassMethod $constructor, Node\Expr\New_ $newExpr, array $instance, string $className = ''): array
    {
        $stmts = [];

        foreach ($constructor->stmts as $stmt) {
            // Глубокая копия тела — shallow clone оставил бы поддеревья общими
            // с оригиналом, и обход замен мутировал бы исходный метод
            $clonedStmt = $this->deepClone($stmt);
            $traverser = new NodeTraverser();
            $traverser->addVisitor(new ThisPropertyReplacer($instance));
            $traverser->addVisitor(new ParametersToArgsVisitor($newExpr));
            if ($className !== '') {
                $traverser->addVisitor(new SelfStaticReplacer($className));
            }

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

        if (!$node->name instanceof Node\Identifier) {
            return null; // динамический вызов $obj->$method() — не инлайним
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
            return $this->replaceCallToBody($node, $method, $instance, $className);
        }

        // Если метод не найден, проверяем родительский класс
        if ($class['parent'] !== null) {
            $parentClass = $this->scopeStore->getClassFromGlobal($class['parent']);
            if ($parentClass !== null && isset($parentClass['methods'][$methodName])) {
                $method = $parentClass['methods'][$methodName];
                return $this->replaceCallToBody($node, $method, $instance, $className);
            }
        }

        return null;
    }

    protected function replaceCallToBody(
        Node\Expr\MethodCall $call,
        Node\Stmt\ClassMethod $method,
        array $instance,
        string $className = ''
    ): ?Node\Expr {
        if (count($method->stmts) === 1 && $method->stmts[0] instanceof Node\Stmt\Return_) {
            // Глубокая копия тела обязательна: иначе каждая последующая подстановка
            // аргументов будет видеть изменения предыдущей (общие поддеревья).
            $cloned = $this->deepClone($method->stmts[0]);

            $traverser = new NodeTraverser();
            $traverser->addVisitor(new ThisPropertyReplacer($instance));
            $traverser->addVisitor(new ParametersToArgsVisitor($call));
            if ($className !== '') {
                $traverser->addVisitor(new SelfStaticReplacer($className));
            }

            $result = $traverser->traverse([$cloned]);

            // Возвращаем выражение из return (без самого return)
            if ($result[0] instanceof Node\Stmt\Return_ && $result[0]->expr instanceof Node\Expr) {
                return $result[0]->expr;
            }
        }

        return null;
    }

    /**
     * Объект можно заменить переменными свойств, только если после этого не останется
     * ссылок на сам объект:
     * - присваивание на верхнем уровне (в методе `$this` тела конструктора — чужой объект);
     * - конструктор только записывает свои свойства (без parent::, вызовов $this->m());
     * - переменная используется лишь как `$var->prop` или `$var->m()`, где m — один
     *   return, тоже читающий только свои свойства (такие вызовы встраиваются).
     *
     * @param array<string, mixed> $class слепок класса из ScopeStore
     */
    private function canScalarize(Node\Expr\Assign $assign, array $class): bool
    {
        for ($scope = $assign->getAttribute('parent'); $scope instanceof Node; $scope = $scope->getAttribute('parent')) {
            if ($scope instanceof Node\FunctionLike || $scope instanceof Node\Stmt\ClassLike) {
                return false;
            }
        }

        $methods = is_array($class['methods'] ?? null) ? $class['methods'] : [];
        $parent  = is_string($class['parent'] ?? null) ? $this->scopeStore->getClassFromGlobal($class['parent']) : null;
        if (is_array($parent) && is_array($parent['methods'] ?? null)) {
            $methods += $parent['methods'];
        }

        $ctor = $methods['__construct'] ?? null;
        if ($ctor instanceof Node\Stmt\ClassMethod && !$this->usesOnlyOwnProps($ctor->stmts ?? [])) {
            return false;
        }

        $name = $assign->var instanceof Node\Expr\Variable ? $assign->var->name : null;
        $ok   = true;
        BodyInliner::walkOwnScope($this->ast, function (Node $node) use ($name, $assign, $methods, &$ok): void {
            if (!$node instanceof Node\Expr\Variable || $node->name !== $name || $node === $assign->var) {
                return;
            }

            $use = $node->getAttribute('parent');
            if ($use instanceof Node\Expr\PropertyFetch && $use->var === $node && $use->name instanceof Node\Identifier) {
                return;
            }

            $method = $use instanceof Node\Expr\MethodCall && $use->var === $node && $use->name instanceof Node\Identifier
                ? $methods[$use->name->toString()] ?? null
                : null;
            if (
                $method instanceof Node\Stmt\ClassMethod
                && count($method->stmts ?? []) === 1
                && ($method->stmts[0] ?? null) instanceof Node\Stmt\Return_
                && $this->usesOnlyOwnProps($method->stmts ?? [])
            ) {
                return;
            }

            $ok = false;
        });

        return $ok;
    }

    /**
     * `$this` только как `$this->prop`, без parent::.
     *
     * @param array<Node\Stmt> $stmts
     */
    private function usesOnlyOwnProps(array $stmts): bool
    {
        foreach ($this->findNode(Node::class, $stmts) as $node) {
            if ($node instanceof Node\Expr\Variable && $node->name === 'this') {
                $use = $node->getAttribute('parent');
                if (!$use instanceof Node\Expr\PropertyFetch || !$use->name instanceof Node\Identifier) {
                    return false;
                }
            }

            if (
                (
                    $node instanceof Node\Expr\StaticCall
                    || $node instanceof Node\Expr\StaticPropertyFetch
                    || $node instanceof Node\Expr\ClassConstFetch
                )
                && $node->class instanceof Node\Name
                && strtolower($node->class->toString()) === 'parent'
            ) {
                return false;
            }
        }

        return true;
    }

    /** Сколько раз переменная присваивания записывается в своей области видимости. */
    private function assignCount(Node\Expr\Assign $assign): int
    {
        $scope = $assign->getAttribute('parent');
        while ($scope instanceof Node && !$scope instanceof Node\FunctionLike || $scope instanceof Node\Expr\ArrowFunction) {
            $scope = $scope->getAttribute('parent');
        }

        $key = $scope instanceof Node ? spl_object_id($scope) : 0;
        $this->assignCounts[$key] ??= ObjectMethodInlinerVisitor::topLevelAssignCounts(
            $scope instanceof Node\FunctionLike ? $scope->getStmts() ?? [] : $this->ast,
        );

        return $assign->var instanceof Node\Expr\Variable && is_string($assign->var->name)
            ? $this->assignCounts[$key][$assign->var->name] ?? 0
            : 0;
    }

    #[\Override]
    protected function deepClone(Node $node): Node
    {
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new CloningVisitor());

        $result = $traverser->traverse([$node]);
        return $result[0] instanceof Node ? $result[0] : $node;
    }
}
