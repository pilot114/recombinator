<?php

declare(strict_types=1);

namespace Recombinator\Analysis;

use PhpParser\Node;
use PhpParser\NodeFinder;
use Recombinator\Support\Sandbox;
use Recombinator\Support\TypeInference;

/**
 * Транзитивная чистота функций и методов программы: результат зависит только от
 * аргументов (и объектов, созданных во время вызова), а вызов ничего не меняет снаружи.
 *
 * Нечистота тела (локальная причина):
 * - вывод (echo, print, exit, inline HTML), include, eval;
 * - global, static-переменные, суперглобальные массивы;
 * - запись в статическое свойство и чтение статического свойства, в которое кто-то пишет;
 * - вызов встроенной функции не из списка чистых;
 * - динамический вызов, `new $class`, `$obj->$method()`;
 * - вызов метода, цель которого не нашлась среди методов программы;
 * - `new` встроенного класса, кроме исключений и контейнеров SPL.
 *
 * Запись в свойства объектов допустима: при вычислении с константными аргументами все
 * объекты создаются во время вызова. Нечистота распространяется по графу вызовов
 * (с конструкторами и деструкторами создаваемых классов) до неподвижной точки.
 */
final class PurityAnalyzer
{
    /** Встроенные функции без побочных эффектов сверх списка песочницы (детерминированные, без глобального состояния). */
    public const array EXTRA_PURE = [
        'is_a', 'get_class', 'get_parent_class', 'get_object_vars', 'get_debug_type', 'func_get_args', 'func_num_args', 'iterator_to_array',
        'iterator_count', 'is_callable', 'is_iterable', 'is_countable', 'array_key_exists', 'array_key_first', 'array_key_last',
        'array_is_list', 'array_any', 'array_all', 'array_find', 'array_find_key', 'array_map', 'array_filter', 'array_walk', 'array_reduce',
        'usort', 'uasort', 'uksort', 'sort', 'rsort', 'ksort', 'krsort', 'asort', 'arsort', 'array_push', 'array_pop', 'array_shift',
        'array_unshift', 'array_splice', 'preg_replace_callback', 'call_user_func', 'call_user_func_array', 'assert', 'compact',
        'key', 'current', 'next', 'reset', 'end', 'prev', 'spl_object_id', 'spl_object_hash', 'get_called_class',
    ];

    /**
     * Вопросы о наличии классов, функций, констант: ответ зависит от всей программы и
     * сервера. Чисты, только если первый аргумент — литерал с именем класса (функции)
     * программы: тогда он есть и при вычислении.
     */
    public const array EXISTENCE_QUERIES = [
        'class_exists', 'interface_exists', 'enum_exists', 'trait_exists', 'method_exists', 'property_exists', 'is_subclass_of',
        'function_exists', 'defined', 'constant', 'class_parents', 'class_implements', 'class_uses', 'get_class_methods', 'get_class_vars',
    ];

    /** Встроенные функции, принимающие callable: вызов возможен только через свои аргументы. */
    public const array CALLBACK_TAKERS = [
        'array_map' => 0, 'array_filter' => 1, 'array_walk' => 1, 'array_reduce' => 1, 'usort' => 1, 'uasort' => 1, 'uksort' => 1,
        'preg_replace_callback' => 1, 'call_user_func' => 0, 'call_user_func_array' => 0, 'array_any' => 1, 'array_all' => 1,
        'array_find' => 1, 'array_find_key' => 1,
    ];

    /** Встроенные классы, создание которых ничего не меняет снаружи. */
    private const array PURE_BUILTIN_CLASSES = ['arrayiterator', 'arrayobject', 'splobjectstorage', 'weakmap', 'splqueue', 'splstack', 'spldoublylinkedlist', 'stdclass'];

    /** @var array<string, Node\Stmt\Function_|Node\Stmt\ClassMethod> ключ ('fn:имя' или 'класс::метод') → объявление */
    private array $functions = [];

    /** @var array<string, list<string>> */
    private array $deps = [];

    /** @var array<string, string> ключ → причина нечистоты */
    private array $impure = [];

    /** @var array<string, list<string>> имя метода → ключи */
    private array $byName = [];

    /** @var array<string, true> статические свойства, в которые где-то пишут */
    private array $writtenStatics = [];

    private readonly TypeInference $inference;

    private readonly NodeFinder $finder;

    /**
     * @param array<string, Node\Stmt\ClassLike> $classes      lowercase имя → класс
     * @param array<Node>                        $ast
     * @param bool                               $wholeProgram код выполняется вместе со всей программой (а не с выборкой
     *                                                         объявлений) и её статическое состояние снимается: вопросы
     *                                                         о наличии классов и функций, статические свойства чисты
     */
    public function __construct(private readonly array $classes, array $ast, private readonly bool $wholeProgram = false)
    {
        $this->finder    = new NodeFinder();
        $this->inference = new TypeInference($classes, $ast);
        foreach ($classes as $lower => $class) {
            foreach ($class->getMethods() as $method) {
                $key                                               = $lower . '::' . $method->name->toLowerString();
                $this->functions[$key]                             = $method;
                $this->byName[$method->name->toLowerString()][] = $key;
            }
        }

        foreach ($this->finder->findInstanceOf($ast, Node\Stmt\Function_::class) as $function) {
            $this->functions['fn:' . $function->name->toLowerString()] = $function;
        }

        foreach ($this->finder->findInstanceOf($ast, Node\Expr\StaticPropertyFetch::class) as $fetch) {
            if ($this->isWrite($fetch)) {
                $this->writtenStatics[$fetch->name instanceof Node\VarLikeIdentifier ? $fetch->name->toString() : '*'] = true;
            }
        }

        foreach ($this->functions as $key => $function) {
            $this->analyze($key, $function);
        }

        $this->propagate();
    }

    public function isPure(string $key): bool
    {
        return isset($this->functions[$key]) && !isset($this->impure[$key]);
    }

    public function reason(string $key): ?string
    {
        return $this->impure[$key] ?? null;
    }

    /** Чистая функция программы по имени. */
    public function isPureFunction(string $name): bool
    {
        return $this->isPure('fn:' . strtolower(ltrim($name, '\\')));
    }

    /** Ключ метода (с поиском в предках), который вызовет `Класс::метод`. */
    public function methodKey(string $class, string $method): ?string
    {
        $node = $this->classes[strtolower(ltrim($class, '\\'))] ?? null;
        if (!$node instanceof Node\Stmt\Class_) {
            return null;
        }

        $found = $this->inference->lookup($node, $method);
        $owner = $found?->getAttribute('parent');

        return $found instanceof Node\Stmt\ClassMethod && $owner instanceof Node\Stmt\ClassLike && $owner->name instanceof Node\Identifier
            ? $owner->name->toLowerString() . '::' . $found->name->toLowerString()
            : null;
    }

    /** Создание объекта класса (конструкторы и деструкторы всей цепочки предков) чисто. */
    public function isPureConstruction(string $class): bool
    {
        $lower = strtolower(ltrim($class, '\\'));
        if (!isset($this->classes[$lower])) {
            return $this->pureBuiltinClass($lower);
        }

        return array_all($this->lifecycle($lower), fn(string $key): bool => $this->isPure($key));
    }

    /**
     * Функции и методы, от которых зависит ключ (транзитивно, включая сам ключ).
     *
     * @return list<string>
     */
    public function closure(string $key): array
    {
        $seen  = [];
        $queue = [$key];
        while ($queue !== []) {
            $current = array_pop($queue);
            if (isset($seen[$current])) {
                continue;
            }

            $seen[$current] = true;
            foreach ($this->deps[$current] ?? [] as $dep) {
                $queue[] = $dep;
            }
        }

        return array_keys($seen);
    }

    /**
     * Причина нечистоты кода верхнего уровня (с графом вызовов); null — чист. Тела
     * замыканий не учитываются: создание замыкания ничего не выполняет, а его вызов —
     * динамический (нечистый).
     *
     * @param array<Node\Stmt> $stmts
     */
    public function codeEffect(array $stmts): ?string
    {
        $key              = 'code:' . spl_object_id($stmts[0] ?? new Node\Stmt\Nop());
        $this->deps[$key] = [];
        $reason           = null;
        $nodes            = $this->finder->find($stmts, static fn(Node $n): bool => true);
        $inClosure        = [];
        foreach ($this->finder->find($stmts, static fn(Node $n): bool => $n instanceof Node\Expr\Closure || $n instanceof Node\Expr\ArrowFunction) as $closure) {
            foreach ($this->finder->find([$closure], static fn(Node $n): bool => true) as $inner) {
                $inClosure[spl_object_id($inner)] = $inner !== $closure;
            }
        }

        foreach ($nodes as $node) {
            if ($inClosure[spl_object_id($node)] ?? false) {
                continue;
            }

            $reason ??= $this->localEffect($node, $key, null);
        }

        if ($reason !== null) {
            return $reason;
        }

        foreach ($this->closure($key) as $dependency) {
            if ($dependency !== $key && !$this->isPure($dependency)) {
                return $dependency . ': ' . ($this->impure[$dependency] ?? 'unknown');
            }
        }

        return null;
    }

    private function analyze(string $key, Node\Stmt\Function_|Node\Stmt\ClassMethod $function): void
    {
        $this->deps[$key] = [];
        if ($function->stmts === null) {
            $this->impure[$key] = 'abstract';

            return;
        }

        $owner     = $function->getAttribute('parent');
        $ownerName = $owner instanceof Node\Stmt\ClassLike && $owner->name instanceof Node\Identifier ? $owner->name->toLowerString() : null;
        foreach ($this->finder->find($function->stmts, static fn(Node $n): bool => true) as $node) {
            $reason = $this->localEffect($node, $key, $ownerName);
            if ($reason !== null) {
                $this->impure[$key] ??= $reason;
            }
        }
    }

    private function localEffect(Node $node, string $key, ?string $ownerName): ?string
    {
        return match (true) {
            $node instanceof Node\Stmt\Echo_, $node instanceof Node\Expr\Print_, $node instanceof Node\Expr\Exit_, $node instanceof Node\Stmt\InlineHTML => 'output',
            $node instanceof Node\Expr\Include_, $node instanceof Node\Expr\Eval_, $node instanceof Node\Expr\ShellExec => 'include/eval',
            $node instanceof Node\Stmt\Global_ => 'global',
            $node instanceof Node\Stmt\Static_ => 'static variable',
            $node instanceof Node\Expr\Variable => is_string($node->name) && in_array($node->name, ['GLOBALS', '_GET', '_POST', '_SERVER', '_COOKIE', '_FILES', '_ENV', '_REQUEST', '_SESSION'], true)
                ? 'superglobal'
                : (!is_string($node->name) ? 'variable variable' : null),
            $node instanceof Node\Expr\StaticPropertyFetch => $this->staticProperty($node),
            $node instanceof Node\Expr\FuncCall => $this->funcCall($node, $key),
            $node instanceof Node\Expr\New_ => $this->newExpr($node, $key, $ownerName),
            $node instanceof Node\Expr\MethodCall, $node instanceof Node\Expr\NullsafeMethodCall => $this->methodCall($node, $key),
            $node instanceof Node\Expr\StaticCall => $this->staticCall($node, $key, $ownerName),
            default => null,
        };
    }

    private function staticProperty(Node\Expr\StaticPropertyFetch $fetch): ?string
    {
        if ($this->wholeProgram) {
            return null; // состояние статических свойств входит в снимок
        }

        if ($this->isWrite($fetch)) {
            return 'static property write';
        }

        $name = $fetch->name instanceof Node\VarLikeIdentifier ? $fetch->name->toString() : '*';

        return isset($this->writtenStatics[$name]) || isset($this->writtenStatics['*']) || $name === '*' ? 'mutable static property' : null;
    }

    private function funcCall(Node\Expr\FuncCall $call, string $key): ?string
    {
        if (!$call->name instanceof Node\Name) {
            // Вызов замыкания из переменной: тело замыкания — часть анализируемого кода, но
            // вызвать можно и пришедшее снаружи
            return 'dynamic call';
        }

        if ($call->isPartialFunctionApplication()) {
            return 'partial application'; // f(...), f(?, 1): аргументы не по позициям — консервативно
        }

        $name = strtolower(ltrim($call->name->toString(), '\\'));
        if (isset($this->functions['fn:' . $name])) {
            $this->deps[$key][] = 'fn:' . $name;

            return null;
        }

        // is_a($s, X, true): строка — имя класса, который может быть не объявлен при вычислении
        $allowString = ($call->getArgs()[2] ?? null)?->value;
        if ($name === 'is_a' && $allowString !== null && (!$allowString instanceof Node\Expr\ConstFetch || $allowString->name->toLowerString() !== 'false')) {
            $name = 'is_subclass_of';
        }

        if (in_array($name, self::EXISTENCE_QUERIES, true) && !$this->wholeProgram) {
            $first = ($call->getArgs()[0] ?? null)?->value;
            $known = $first instanceof Node\Scalar\String_ && ($name === 'function_exists'
                ? isset($this->functions['fn:' . strtolower(ltrim($first->value, '\\'))])
                : !in_array($name, ['defined', 'constant'], true) && isset($this->classes[strtolower(ltrim($first->value, '\\'))]));

            return $known ? null : 'environment query ' . $name;
        }

        if (!Sandbox::isPureFunction($name) && !in_array($name, self::EXTRA_PURE, true)) {
            return 'call ' . $name;
        }

        // callable — замыкание из этого же тела (уже проверено) или имя чистой функции
        $position = self::CALLBACK_TAKERS[$name] ?? null;
        $callback = $position === null ? null : ($call->getArgs()[$position] ?? null)?->value;
        if ($position !== null) {
            if ($callback instanceof Node\Expr\Closure || $callback instanceof Node\Expr\ArrowFunction || $callback === null) {
                return null;
            }

            if ($callback instanceof Node\Scalar\String_) {
                $target = strtolower(ltrim($callback->value, '\\'));
                if (isset($this->functions['fn:' . $target])) {
                    $this->deps[$key][] = 'fn:' . $target;

                    return null;
                }

                return Sandbox::isPureFunction($target) ? null : 'callback ' . $target;
            }

            return 'dynamic callback';
        }

        return null;
    }

    private function newExpr(Node\Expr\New_ $new, string $key, ?string $ownerName): ?string
    {
        if ($new->class instanceof Node\Stmt\Class_) {
            return 'anonymous class';
        }

        if (!$new->class instanceof Node\Name) {
            return 'dynamic new';
        }

        $class = strtolower(ltrim($new->class->toString(), '\\'));
        if (in_array($class, ['self', 'static', 'parent'], true)) {
            if ($class === 'static' || $ownerName === null) {
                return 'new static';
            }

            $owner = $this->classes[$ownerName] ?? null;
            $class = $class === 'self' ? $ownerName
                : ($owner instanceof Node\Stmt\Class_ && $owner->extends instanceof Node\Name ? strtolower(ltrim($owner->extends->toString(), '\\')) : '');
        }

        if (!isset($this->classes[$class])) {
            return $this->pureBuiltinClass($class) ? null : 'new ' . $class;
        }

        foreach ($this->lifecycle($class) as $method) {
            $this->deps[$key][] = $method;
        }

        return null;
    }

    private function methodCall(Node\Expr\MethodCall|Node\Expr\NullsafeMethodCall $call, string $key): ?string
    {
        if (!$call->name instanceof Node\Identifier) {
            return 'dynamic method';
        }

        $name    = $call->name->toLowerString();
        $targets = [];
        $types   = $this->inference->typeOf($call->var);
        foreach ($types ?? [] as $type) {
            foreach ($this->inference->subtypes($type) ?? [] as $class) {
                if ($class instanceof Node\Stmt\Class_ && !$class->isAbstract()) {
                    $found = $this->methodKey((string) $class->name, $name);
                    if ($found === null) {
                        return 'method ' . $name . ' of ' . $class->name;
                    }

                    $targets[] = $found;
                }
            }
        }

        // Тип не определился — любой метод программы с этим именем
        $targets = $targets !== [] ? $targets : $this->byName[$name] ?? [];
        if ($targets === []) {
            return 'external method ' . $name;
        }

        foreach ($targets as $target) {
            $this->deps[$key][] = $target;
        }

        return null;
    }

    private function staticCall(Node\Expr\StaticCall $call, string $key, ?string $ownerName): ?string
    {
        if (!$call->name instanceof Node\Identifier || !$call->class instanceof Node\Name) {
            return 'dynamic static call';
        }

        $class = strtolower(ltrim($call->class->toString(), '\\'));
        $name  = $call->name->toLowerString();
        if ($class === 'static') {
            // Позднее связывание: любой одноимённый метод
            $targets = $this->byName[$name] ?? [];
        } else {
            if ($class === 'self' || $class === 'parent') {
                $owner = $ownerName === null ? null : $this->classes[$ownerName] ?? null;
                $class = $class === 'self' ? (string) $ownerName
                    : ($owner instanceof Node\Stmt\Class_ && $owner->extends instanceof Node\Name ? strtolower(ltrim($owner->extends->toString(), '\\')) : '');
            }

            $found   = $this->methodKey($class, $name);
            $targets = $found === null ? [] : [$found];
        }

        if ($targets === []) {
            return 'external static ' . $class . '::' . $name;
        }

        foreach ($targets as $target) {
            $this->deps[$key][] = $target;
        }

        return null;
    }

    /** @return list<string> конструкторы и деструкторы класса и предков */
    private function lifecycle(string $class): array
    {
        $result = [];
        $node   = $this->classes[$class] ?? null;
        for ($depth = 0; $node instanceof Node\Stmt\ClassLike && $depth < 32; $depth++) {
            foreach (['__construct', '__destruct'] as $method) {
                if ($node->getMethod($method) instanceof Node\Stmt\ClassMethod && $node->name instanceof Node\Identifier) {
                    $result[] = $node->name->toLowerString() . '::' . $method;
                }
            }

            $parent = $node instanceof Node\Stmt\Class_ && $node->extends instanceof Node\Name ? strtolower(ltrim($node->extends->toString(), '\\')) : null;
            if ($parent !== null && !isset($this->classes[$parent]) && !$this->pureBuiltinClass($parent)) {
                $result[] = 'external:' . $parent;
                $this->impure['external:' . $parent] = 'external parent ' . $parent;
            }

            $node = $parent === null ? null : $this->classes[$parent] ?? null;
        }

        return $result;
    }

    private function pureBuiltinClass(string $class): bool
    {
        if (in_array($class, self::PURE_BUILTIN_CLASSES, true)) {
            return true;
        }

        // Встроенные исключения: конструктор только сохраняет сообщение
        return (class_exists($class, false) || interface_exists($class, false))
            && is_a($class, \Throwable::class, true) && new \ReflectionClass($class)->isInternal();
    }

    private function isWrite(Node\Expr\StaticPropertyFetch $fetch): bool
    {
        $child  = $fetch;
        $parent = $fetch->getAttribute('parent');
        while ($parent instanceof Node\Expr\ArrayDimFetch || $parent instanceof Node\Expr\PropertyFetch) {
            $child  = $parent;
            $parent = $parent->getAttribute('parent');
        }

        return ($parent instanceof Node\Expr\Assign || $parent instanceof Node\Expr\AssignOp || $parent instanceof Node\Expr\AssignRef
                || $parent instanceof Node\Expr\PreInc || $parent instanceof Node\Expr\PreDec || $parent instanceof Node\Expr\PostInc
                || $parent instanceof Node\Expr\PostDec)
                && $parent->var === $child
            || $parent instanceof Node\Arg && $parent->byRef
            || $parent instanceof Node\Stmt\Unset_;
    }

    private function propagate(): void
    {
        $reverse = [];
        foreach ($this->deps as $key => $deps) {
            foreach ($deps as $dep) {
                $reverse[$dep][] = $key;
            }
        }

        $queue = array_keys($this->impure);
        while ($queue !== []) {
            $current = array_pop($queue);
            foreach ($reverse[$current] ?? [] as $caller) {
                if (!isset($this->impure[$caller])) {
                    $this->impure[$caller] = '→ ' . $current;
                    $queue[]               = $caller;
                }
            }
        }
    }
}
