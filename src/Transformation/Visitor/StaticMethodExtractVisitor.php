<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * Выносит статические методы из классов в функции:
 *
 *   class DriverManager { public static function getConnection(array $p) { ... self::createDriver(...) ... } }
 *   DriverManager::getConnection($params);
 *   →
 *   function DriverManager_getConnection(array $p) { ... DriverManager_createDriver(...) ... }
 *   DriverManager_getConnection($params);
 *
 * Класс, у которого не осталось членов, удалит RemoveUnusedClassVisitor; функции
 * дальше встраивает CallFunctionVisitor.
 *
 * Тело выносится, если оно не обращается к закрытым членам объектов (`$x->priv`:
 * функция вне класса их не видит), к `$this`/`parent::`, и (у класса с наследниками)
 * не использует позднее связывание `static` и не переопределено в наследнике.
 * Закрытые статические свойства, константы и конструктор того же класса становятся
 * public — для корректной программы это ничего не меняет.
 *
 * Метод удаляется из класса целиком, если его можно вызвать только переписываемыми
 * способами. Иначе в классе остаётся обёртка `static function m($a) { return C_m($a); }`:
 * - у класса есть наследники (`Child::m()` переписывается по цепочке предков, но
 *   объект наследника может вызвать `m` как угодно);
 * - метод объявлен в интерфейсе класса (контракт implements);
 * - есть вызовы `$obj->m()`, динамические `$cls::m()`, `C::$m()` или строки 'm' кроме
 *   распознанных callable (`'C::m'`, `[C::class, 'm']`).
 * Обёртке нужны те же аргументы: тело с func_get_args()/func_num_args() и метод с
 * возвратом по ссылке не выносятся.
 *
 * __CLASS__, __METHOD__, __FUNCTION__, get_called_class() заменяются строками.
 */
#[VisitorMeta('Статические методы → функции: Class::m() → Class_m()')]
class StaticMethodExtractVisitor extends BaseVisitor
{
    /** @var array<string, array<string, string>> lowercase класс → lowercase метод → имя функции */
    private array $extracted = [];


    /** @var array<string, string> lowercase класс → lowercase родитель */
    private array $parents = [];

    /** @var array<string, array<string, true>> lowercase класс → объявленные в нём методы */
    private array $declared = [];

    /** @var list<string> стек lowercase имён объемлющих классов */
    private array $classStack = [];

    #[\Override]
    public function beforeTraverse(array $nodes): ?array
    {
        parent::beforeTraverse($nodes);
        $this->extracted  = [];
        $this->classStack = [];

        if ($this->hasPendingIncludes() || array_any($nodes, static fn(Node $n): bool => $n instanceof Node\Stmt\Namespace_)) {
            return null;
        }

        /** @var array<string, Node\Stmt\ClassLike> $declarations */
        $declarations  = [];
        $extended      = [];
        $functions     = [];
        $this->parents = [];
        $this->declared = [];
        foreach ($this->findNode(Node::class) as $node) {
            if ($node instanceof Node\Stmt\ClassLike && $node->name instanceof Node\Identifier) {
                $lower                  = strtolower($node->name->toString());
                $declarations[$lower]   = $node;
                foreach ($node->getMethods() as $declaredMethod) {
                    $this->declared[$lower][$declaredMethod->name->toLowerString()] = true;
                }

                if ($node instanceof Node\Stmt\Class_ && $node->extends instanceof Node\Name) {
                    $parent                 = strtolower(ltrim($node->extends->toString(), '\\'));
                    $extended[$parent]      = true;
                    $this->parents[$lower] = $parent;
                }
            } elseif ($node instanceof Node\Stmt\Function_) {
                $functions[strtolower($node->name->toString())] = true;
            }
        }

        [$blockedNames, $blockedClasses] = $this->dynamicUses();
        $staticNames                     = $this->staticCallNames();

        // Кандидаты: статические методы классов верхнего уровня
        $candidates = [];
        foreach ($nodes as $class) {
            if (!$class instanceof Node\Stmt\Class_ || !$class->name instanceof Node\Identifier) {
                continue;
            }

            $lower       = strtolower($class->name->toString());
            $descendants = isset($extended[$lower]) ? $this->descendants($lower) : [];
            foreach ($class->getMethods() as $method) {
                $name     = $method->name->toString();
                $key      = strtolower($name);
                $function = $class->name->toString() . '_' . $name;
                // Обёртка от прошлого прохода остаётся обёрткой, даже если в неё встроили тело
                if (
                    !$method->isStatic() || $method->isAbstract() || $method->stmts === null || isset($functions[strtolower($function)])
                    || $method->getAttribute('staticWrapper') === true
                ) {
                    continue;
                }

                // Наследник переопределяет метод или тело зависит от позднего связывания
                if ($descendants !== [] && (array_any($descendants, fn(string $d): bool => isset($this->declared[$d][$key])) || $this->usesLateBinding($method))) {
                    continue;
                }

                $wrap = $descendants !== [] || isset($blockedNames[$key]) || isset($blockedClasses[$lower])
                    || $this->declaredByInterface($class, $name, $declarations);
                // Обёртка оправдана, только если есть статические вызовы, которые станут функцией
                if ($wrap && ($method->byRef || $this->usesArgumentList($method) || !isset($staticNames[$key]))) {
                    continue;
                }

                $candidates[$lower][$key] = [$class, $method, $function, $wrap];
            }
        }

        // Тело с обращением к закрытым членам объектов класса вынести нельзя
        foreach ($candidates as $lower => $methods) {
            $private = $this->privateInstanceMembers(reset($methods)[0]);
            foreach ($methods as $key => [$class, $method]) {
                if ($this->touchesPrivateInstanceMembers($method, $private) || $this->usesParentOrThis($method)) {
                    unset($candidates[$lower][$key]);
                }
            }
        }

        foreach ($candidates as $lower => $methods) {
            foreach ($methods as $key => [, , $function]) {
                $this->extracted[$lower][$key] = $function;
            }
        }

        if ($this->extracted === []) {
            return null;
        }

        return $this->extract($nodes, $candidates);
    }

    /**
     * @param  array<Node>                                                                 $nodes
     * @param  array<string, array<string, array{Node\Stmt\Class_, Node\Stmt\ClassMethod, string, bool}>> $candidates
     * @return array<Node>
     */
    private function extract(array $nodes, array $candidates): array
    {
        $result = [];
        foreach ($nodes as $node) {
            $result[] = $node;
            if (!$node instanceof Node\Stmt\Class_ || !$node->name instanceof Node\Identifier) {
                continue;
            }

            $methods = $candidates[strtolower($node->name->toString())] ?? [];
            if ($methods === []) {
                continue;
            }

            $this->publicizeStaticMembers($node);
            foreach ($methods as [$class, $method, $function]) {
                $result[] = $this->toFunction($class, $method, $function);
            }

            // Обёртка остаётся вместо тела; остальные методы удаляются
            $removed = [];
            foreach ($methods as [, $method, $function, $wrap]) {
                if ($wrap) {
                    $method->stmts = [$this->forward($method, $function)];
                    $method->setAttribute('staticWrapper', true);
                } else {
                    $removed[] = $method;
                }
            }

            $node->stmts = array_values(array_filter($node->stmts, static fn(Node\Stmt $s): bool => !in_array($s, $removed, true)));
        }

        return $result;
    }

    private function toFunction(Node\Stmt\Class_ $class, Node\Stmt\ClassMethod $method, string $name): Node\Stmt\Function_
    {
        $className = $class->name?->toString() ?? '';
        $copy      = $this->deepClone($method);
        assert($copy instanceof Node\Stmt\ClassMethod);

        // self::/static:: → имя класса, магические константы → их значения
        $copy = new NodeTraverser(new class ($className, $method->name->toString()) extends NodeVisitorAbstract {
            public function __construct(private readonly string $class, private readonly string $method)
            {
            }

            public function leaveNode(Node $node): ?Node
            {
                if (
                    $node instanceof Node\Name
                    && in_array(strtolower($node->toString()), ['self', 'static'], true)
                ) {
                    return new Node\Name\FullyQualified($this->class);
                }

                if ($node instanceof Node\Scalar\MagicConst\Class_) {
                    return new Node\Scalar\String_($this->class);
                }

                if ($node instanceof Node\Scalar\MagicConst\Method) {
                    // Значение __METHOD__, а не callable: переписывать в имя функции нельзя
                    return new Node\Scalar\String_($this->class . '::' . $this->method, ['magicConstant' => true]);
                }

                if ($node instanceof Node\Scalar\MagicConst\Function_) {
                    return new Node\Scalar\String_($this->method);
                }

                if (
                    $node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name
                    && strtolower($node->name->toString()) === 'get_called_class'
                ) {
                    return new Node\Scalar\String_($this->class);
                }

                return null;
            }
        })->traverse([$copy])[0];
        assert($copy instanceof Node\Stmt\ClassMethod);

        return new Node\Stmt\Function_(new Node\Identifier($name), [
            'byRef'      => $copy->byRef,
            'params'     => $copy->params,
            'returnType' => $copy->returnType,
            'stmts'      => $copy->stmts ?? [],
            'attrGroups' => $copy->attrGroups,
        ], ['extractedFrom' => $class->name?->toString()]);
    }

    /** Вызовы, строки-callable и `C::m(...)` вынесенных методов → функции. */
    public function enterNode(Node $node): ?Node
    {
        if ($node instanceof Node\Stmt\ClassLike) {
            $this->classStack[] = $node->name instanceof Node\Identifier ? strtolower($node->name->toString()) : '';

            return null;
        }

        if ($node instanceof Node\Expr\StaticCall && $node->class instanceof Node\Name && $node->name instanceof Node\Identifier) {
            $function = $this->functionFor($node->class->toString(), $node->name->toString());

            return $function === null ? null : new Node\Expr\FuncCall(new Node\Name\FullyQualified($function), $node->args, $node->getAttributes());
        }

        if ($node instanceof Node\Scalar\String_ && str_contains($node->value, '::') && $node->getAttribute('magicConstant') !== true) {
            [$class, $method] = explode('::', ltrim($node->value, '\\'), 2);
            $function         = $this->functionFor($class, $method);

            return $function === null ? null : new Node\Scalar\String_($function);
        }

        // [C::class, 'm'] / ['C', 'm']
        if ($node instanceof Node\Expr\Array_ && count($node->items) === 2) {
            /** @var array{Node\ArrayItem, Node\ArrayItem} $items */
            $items            = $node->items;
            [$first, $second] = $items;
            $class            = match (true) {
                $first->value instanceof Node\Scalar\String_ => $first->value->value,
                $first->value instanceof Node\Expr\ClassConstFetch && $first->value->class instanceof Node\Name
                    && $first->value->name instanceof Node\Identifier && strtolower($first->value->name->toString()) === 'class'
                    => $first->value->class->toString(),
                default => null,
            };
            if ($class !== null && $first->key === null && $second->key === null && $second->value instanceof Node\Scalar\String_) {
                $function = $this->functionFor($class, $second->value->value);

                return $function === null ? null : new Node\Scalar\String_($function);
            }
        }

        return null;
    }

    #[\Override]
    public function leaveNode(Node $node): int|Node|array|null
    {
        if ($node instanceof Node\Stmt\ClassLike) {
            array_pop($this->classStack);
        }

        return parent::leaveNode($node);
    }

    /** Функция для `C::m`: метод ищется вверх по цепочке предков, как это делает PHP. */
    private function functionFor(string $class, string $method): ?string
    {
        $lower = strtolower(ltrim($class, '\\'));
        if ($lower === 'self' || $lower === 'static') {
            $lower = (string) end($this->classStack);
        } elseif ($lower === 'parent') {
            $lower = $this->parents[(string) end($this->classStack)] ?? '';
        }

        $key  = strtolower($method);
        $seen = [];
        while ($lower !== '' && !isset($seen[$lower])) {
            $seen[$lower] = true;
            if (isset($this->extracted[$lower][$key])) {
                return $this->extracted[$lower][$key];
            }

            if (isset($this->declared[$lower][$key])) {
                return null;
            }

            $lower = $this->parents[$lower] ?? '';
        }

        return null;
    }

    /** @return list<string> lowercase имена всех наследников класса */
    private function descendants(string $lower): array
    {
        $result = [];
        $queue  = [$lower];
        while ($queue !== []) {
            $current = array_pop($queue);
            foreach ($this->parents as $child => $parent) {
                if ($parent === $current && !in_array($child, $result, true)) {
                    $result[] = (string) $child;
                    $queue[]  = (string) $child;
                }
            }
        }

        return $result;
    }

    /** `static::`, `new static`, `static` в типе, get_called_class() — зависят от класса вызова. */
    private function usesLateBinding(Node\Stmt\ClassMethod $method): bool
    {
        if ($method->returnType instanceof Node\Name && $method->returnType->toLowerString() === 'static') {
            return true;
        }

        foreach ($this->findNode(Node::class, $method->stmts ?? []) as $node) {
            if ($node instanceof Node\Name && $node->toLowerString() === 'static') {
                return true;
            }

            if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name && $node->name->toLowerString() === 'get_called_class') {
                return true;
            }
        }

        return false;
    }

    private function usesArgumentList(Node\Stmt\ClassMethod $method): bool
    {
        return array_any($this->findNode(Node\Expr\FuncCall::class, $method->stmts ?? []), fn(\PhpParser\Node\Expr\FuncCall $call): bool => $call->name instanceof Node\Name && in_array($call->name->toLowerString(), ['func_get_args', 'func_get_arg', 'func_num_args'], true));
    }

    /** Тело обёртки: `return C_m($a, ...$rest);` (без return для void/never). */
    private function forward(Node\Stmt\ClassMethod $method, string $function): Node\Stmt
    {
        $args = [];
        foreach ($method->params as $param) {
            if ($param->var instanceof Node\Expr\Variable && is_string($param->var->name)) {
                $args[] = new Node\Arg(new Node\Expr\Variable($param->var->name), false, $param->variadic);
            }
        }

        $call    = new Node\Expr\FuncCall(new Node\Name\FullyQualified($function), $args);
        $type    = $method->returnType;
        $returns = !$type instanceof Node\Identifier || !in_array($type->toLowerString(), ['void', 'never'], true);

        return $returns ? new Node\Stmt\Return_($call) : new Node\Stmt\Expression($call);
    }

    /**
     * Имена методов, которые могут вызываться динамически (`$cls::m()`, строка 'm' вне
     * callable), и классы с динамическим именем метода (`C::$m()`).
     *
     * @return array{array<string, true>, array<string, true>}
     */
    private function dynamicUses(): array
    {
        $names   = [];
        $classes = [];
        $anyName = false;
        $callableParts = new \SplObjectStorage();
        foreach ($this->findNode(Node\Expr\Array_::class) as $array) {
            // [C::class, 'm'] / ['C', 'm'] переписываются; [$cls, 'm'] — нет
            $class = $array->items[0]->value ?? null;
            if (
                count($array->items) === 2 && $array->items[1]?->value instanceof Node\Scalar\String_
                && ($class instanceof Node\Scalar\String_ || $class instanceof Node\Expr\ClassConstFetch && $class->class instanceof Node\Name)
            ) {
                $callableParts[$array->items[1]->value] = true;
            }
        }

        foreach ($this->findNode(Node::class) as $node) {
            if ($node instanceof Node\Expr\StaticCall) {
                if (!$node->name instanceof Node\Identifier) {
                    if ($node->class instanceof Node\Name) {
                        $classes[strtolower(ltrim($node->class->toString(), '\\'))] = true;
                    } else {
                        $anyName = true;
                    }
                } elseif (!$node->class instanceof Node\Name) {
                    $names[strtolower($node->name->toString())] = true;
                }
            }

            // Статический метод можно вызвать и через объект: $provider->getFactories()
            if (($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\NullsafeMethodCall) && $node->name instanceof Node\Identifier) {
                $names[strtolower($node->name->toString())] = true;
            }

            if ($node instanceof Node\Scalar\String_ && !isset($callableParts[$node]) && !str_contains($node->value, '::')) {
                $names[strtolower($node->value)] = true;
            }
        }

        if ($anyName) {
            // Вызов с динамическими и классом, и методом — выносить ничего нельзя
            foreach ($this->findNode(Node\Stmt\ClassMethod::class) as $method) {
                $names[strtolower($method->name->toString())] = true;
            }
        }

        return [$names, $classes];
    }

    /**
     * Имена методов (lowercase) в переписываемых вызовах: `X::m()`, `'X::m'`, `[X::class, 'm']`.
     *
     * @return array<string, true>
     */
    private function staticCallNames(): array
    {
        $names = [];
        foreach ($this->findNode(Node::class) as $node) {
            if ($node instanceof Node\Expr\StaticCall && $node->class instanceof Node\Name && $node->name instanceof Node\Identifier) {
                $names[$node->name->toLowerString()] = true;
            } elseif ($node instanceof Node\Scalar\String_ && str_contains($node->value, '::')) {
                $names[strtolower(substr((string) strrchr($node->value, ':'), 1))] = true;
            } elseif ($node instanceof Node\Expr\Array_ && count($node->items) === 2 && $node->items[1]?->value instanceof Node\Scalar\String_) {
                $names[strtolower($node->items[1]->value->value)] = true;
            }
        }

        return $names;
    }

    /** @param array<string, Node\Stmt\ClassLike> $declarations */
    private function declaredByInterface(Node\Stmt\Class_ $class, string $method, array $declarations): bool
    {
        $queue = $class->implements;
        $seen  = [];
        while ($queue !== []) {
            $name  = array_pop($queue);
            $lower = strtolower(ltrim($name->toString(), '\\'));
            if (isset($seen[$lower])) {
                continue;
            }

            $seen[$lower] = true;
            $interface    = $declarations[$lower] ?? null;
            if ($interface instanceof Node\Stmt\Interface_) {
                if ($interface->getMethod($method) instanceof Node\Stmt\ClassMethod) {
                    return true;
                }

                array_push($queue, ...$interface->extends);
            } elseif (!interface_exists($lower, false) || new \ReflectionClass($lower)->hasMethod($method)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, true> имена закрытых свойств и методов экземпляра */
    private function privateInstanceMembers(Node\Stmt\Class_ $class): array
    {
        $names = [];
        foreach ($class->getProperties() as $property) {
            if (!$property->isStatic() && !$property->isPublic()) {
                foreach ($property->props as $prop) {
                    $names[strtolower($prop->name->toString())] = true;
                }
            }
        }

        foreach ($class->getMethods() as $method) {
            if (!$method->isStatic() && !$method->isPublic()) {
                $names[strtolower($method->name->toString())] = true;
            }
        }

        $ctor = $class->getMethod('__construct');
        foreach ($ctor->params ?? [] as $param) {
            $hidden = ($param->flags & (Modifiers::PRIVATE | Modifiers::PROTECTED)) !== 0;
            if ($hidden && $param->var instanceof Node\Expr\Variable && is_string($param->var->name)) {
                $names[strtolower($param->var->name)] = true;
            }
        }

        return $names;
    }

    /** @param array<string, true> $private */
    private function touchesPrivateInstanceMembers(Node\Stmt\ClassMethod $method, array $private): bool
    {
        foreach ($this->findNode(Node::class, $method->stmts ?? []) as $node) {
            $name = match (true) {
                $node instanceof Node\Expr\PropertyFetch, $node instanceof Node\Expr\NullsafePropertyFetch,
                $node instanceof Node\Expr\MethodCall, $node instanceof Node\Expr\NullsafeMethodCall => $node->name,
                default => null,
            };
            if ($name instanceof Node\Identifier ? isset($private[strtolower($name->toString())]) : $name instanceof \PhpParser\Node) {
                return true;
            }
        }

        return false;
    }

    private function usesParentOrThis(Node\Stmt\ClassMethod $method): bool
    {
        foreach ($this->findNode(Node::class, $method->stmts ?? []) as $node) {
            if ($node instanceof Node\Expr\Variable && $node->name === 'this') {
                return true;
            }

            if ($node instanceof Node\Name && strtolower($node->toString()) === 'parent') {
                return true;
            }

            // Замыкание, привязанное к классу (Closure::bind(..., static::class)) — область видимости меняется
            if ($node instanceof Node\Expr\StaticCall && $node->class instanceof Node\Name && strtolower($node->class->toString()) === 'closure') {
                return true;
            }
        }

        return false;
    }

    /** Статические свойства, константы и конструктор становятся доступны функциям. */
    private function publicizeStaticMembers(Node\Stmt\Class_ $class): void
    {
        foreach ($class->stmts as $stmt) {
            $public = match (true) {
                $stmt instanceof Node\Stmt\Property => $stmt->isStatic(),
                $stmt instanceof Node\Stmt\ClassConst => true,
                $stmt instanceof Node\Stmt\ClassMethod => $stmt->name->toLowerString() === '__construct' || $stmt->isStatic(),
                default => false,
            };
            if ($public && property_exists($stmt, 'flags')) {
                $stmt->flags = $stmt->flags & ~(Modifiers::PRIVATE | Modifiers::PROTECTED) | Modifiers::PUBLIC;
            }
        }
    }
}
