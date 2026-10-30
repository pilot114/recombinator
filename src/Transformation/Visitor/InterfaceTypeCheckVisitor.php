<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;
use Recombinator\Support\ExternalCode;
use Recombinator\Support\TypeInference;

/**
 * Заменяет проверки типа по интерфейсу проверками по его реализациям — после этого
 * RemoveInterfacesVisitor удаляет интерфейс (мир замкнут: все реализации известны):
 *
 *   $e instanceof Doctrine_DBAL_Exception          →  $e instanceof A || $e instanceof B
 *   catch (Doctrine_DBAL_Driver_Exception $e)      →  catch (A | B $e)
 *   function f(Collection $c): ?Collection         →  function f(ArrayCollection|PersistentCollection $c): ArrayCollection|PersistentCollection|null
 *
 * Реализация — класс или enum программы, у которого интерфейс среди предков;
 * в списке только «верхние» (наследники покрываются проверкой предка). Нет
 * реализаций — `instanceof` даёт false, catch убирается (try без catch и finally
 * разворачивается).
 *
 * Интерфейс не трогается, если: его имя встречается строкой или в `I::…`; его
 * реализует или расширяет внешний код или анонимный класс; он входит в
 * тип-пересечение; проверяемое instanceof выражение — не переменная/свойство
 * (повторять его нельзя); в типе он без реализаций; есть невстроенный include.
 */
#[VisitorMeta('Проверки типа по интерфейсу → по его реализациям')]
class InterfaceTypeCheckVisitor extends BaseVisitor
{
    private const int MAX_IMPLEMENTORS = 64;

    /** @var array<string, list<string>> lowercase интерфейс → имена реализаций (как объявлены) */
    private array $replace = [];

    /** @var array<string, Node\Stmt\ClassLike> */
    private array $classes = [];

    private ?TypeInference $inference = null;

    /** @var array<string, list<Node\FunctionLike>>|null */
    private ?array $functionsByName = null;

    /** @var array<string, true> параметры, которые сейчас проверяются (защита от циклов) */
    private array $visiting = [];

    /** @param array<string, string> $fileMap */
    public function __construct(private readonly array $fileMap = [])
    {
    }

    #[\Override]
    public function beforeTraverse(array $nodes): ?array
    {
        parent::beforeTraverse($nodes);
        $this->replace         = [];
        $this->functionsByName = null;
        if ($this->hasPendingIncludes()) {
            return null;
        }

        $classes    = [];
        $interfaces = [];
        foreach ($this->findNode(Node\Stmt\ClassLike::class) as $class) {
            if ($class->name instanceof Node\Identifier) {
                $classes[$class->name->toLowerString()] = $class;
                if ($class instanceof Node\Stmt\Interface_) {
                    $interfaces[$class->name->toLowerString()] = $class;
                }
            }
        }

        if ($interfaces === []) {
            return null;
        }

        $inference       = new TypeInference($classes, $nodes);
        $this->classes   = $classes;
        $this->inference = $inference;
        $blocked         = $this->blockedNames();
        $external        = $this->fileMap === [] ? null : ExternalCode::forProgram($this->fileMap, $classes, $nodes);
        $anonymous       = $this->findNode(Node\Stmt\Class_::class);
        $kept            = [];
        foreach ($interfaces as $lower => $interface) {
            $implementors = $this->implementors($lower, $classes, $inference);
            $viaAnonymous = array_any($anonymous, fn(Node\Stmt\Class_ $c): bool => !$c->name instanceof Node\Identifier && $this->anonymousImplements($c, $lower, $inference));
            if (
                isset($blocked[$lower]) || $external?->extendsOrImplements((string) $interface->name) === true
                || $viaAnonymous || count($implementors) > self::MAX_IMPLEMENTORS
            ) {
                $kept[] = $interface;
                continue;
            }

            $this->replace[$lower] = $implementors;
        }

        // Оставленный интерфейс могут реализовать классы вне программы (прокси Doctrine
        // по строковому шаблону) — тогда и проверки по его предкам не заменить
        while ($kept !== []) {
            $interface = array_pop($kept);
            foreach ($interface->extends as $parent) {
                $lower = strtolower(ltrim($parent->toString(), '\\'));
                if (isset($this->replace[$lower], $interfaces[$lower])) {
                    unset($this->replace[$lower]);
                    $kept[] = $interfaces[$lower];
                }
            }
        }

        // Пустой список реализаций допустим только там, где проверку можно убрать
        foreach ($this->findNode(Node\Expr\Instanceof_::class) as $check) {
            $name = $check->class instanceof Node\Name ? strtolower(ltrim($check->class->toString(), '\\')) : null;
            if ($name !== null && isset($this->replace[$name]) && !$this->repeatable($check->expr)) {
                unset($this->replace[$name]);
            }
        }

        foreach ($this->typeNames($nodes) as $name) {
            if (isset($this->replace[$name]) && $this->replace[$name] === []) {
                unset($this->replace[$name]);
            }
        }

        // Пересечение с заменяемым интерфейсом — классы, подходящие под все части
        foreach ($this->findNode(Node\IntersectionType::class) as $intersection) {
            $parts = $this->parts($intersection);
            if (array_any($parts, fn(string $p): bool => isset($this->replace[$p])) && $this->intersectionClasses($parts) === []) {
                foreach ($parts as $part) {
                    unset($this->replace[$part]);
                }
            }
        }

        return null;
    }

    public function enterNode(Node $node): ?Node
    {
        if ($this->replace === []) {
            return null;
        }

        // Оставшиеся строки с именем интерфейса — метки (ключи, сообщения): удалению не мешают
        if ($node instanceof Node\Scalar\String_ && isset($this->replace[strtolower(ltrim($node->value, '\\'))])) {
            $node->setAttribute('interfaceLabel', true);

            return null;
        }

        // is_subclass_of($x, 'I'), in_array('I', class_implements($x)) → is_a($x, 'A', true) || …
        if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name && !$node->isPartialFunctionApplication()) {
            foreach ($node->getArgs() as $arg) {
                $names = $arg->value instanceof Node\Scalar\String_ ? $this->replace[strtolower(ltrim($arg->value->value, '\\'))] ?? null : null;
                $subject = $names !== null && $arg->value instanceof Node\Scalar\String_ ? $this->checkedSubject($node, $arg->value) : null;
                if ($subject instanceof Node\Expr) {
                    $result = null;
                    foreach ($names ?? [] as $class) {
                        $check  = new Node\Expr\FuncCall(new Node\Name\FullyQualified('is_a'), [
                            new Node\Arg(clone $subject), new Node\Arg(new Node\Scalar\String_($class)), new Node\Arg(new Node\Expr\ConstFetch(new Node\Name('true'))),
                        ]);
                        $result = $result === null ? $check : new Node\Expr\BinaryOp\BooleanOr($result, $check);
                    }

                    return $result ?? new Node\Expr\ConstFetch(new Node\Name('false'));
                }
            }
        }

        if ($node instanceof Node\Expr\Instanceof_ && $node->class instanceof Node\Name) {
            $names = $this->replace[strtolower(ltrim($node->class->toString(), '\\'))] ?? null;
            if ($names === null) {
                return null;
            }

            $result = null;
            foreach ($names as $name) {
                $check  = new Node\Expr\Instanceof_(clone $node->expr, new Node\Name\FullyQualified($name));
                $result = $result === null ? $check : new Node\Expr\BinaryOp\BooleanOr($result, $check);
            }

            return $result ?? new Node\Expr\ConstFetch(new Node\Name('false'));
        }

        if ($node instanceof Node\Stmt\TryCatch) {
            foreach ($node->catches as $catch) {
                $catch->types = $this->expandNames($catch->types);
            }

            $node->catches = array_values(array_filter($node->catches, static fn(Node\Stmt\Catch_ $c): bool => $c->types !== []));
            if ($node->catches === [] && !$node->finally instanceof Node\Stmt\Finally_) {
                $node->setAttribute('replace', $node->stmts);
            }

            return null;
        }

        if ($node instanceof Node\Param || $node instanceof Node\Stmt\Property || $node instanceof Node\Stmt\ClassConst) {
            $node->type = $this->expandType($node->type);
        } elseif ($node instanceof Node\Stmt\ClassMethod || $node instanceof Node\Stmt\Function_ || $node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction) {
            $node->returnType = $this->expandType($node->returnType);
        }

        return null;
    }

    /**
     * @param  array<Node\Name> $names
     * @return list<Node\Name>
     */
    private function expandNames(array $names): array
    {
        $result = [];
        $seen   = [];
        foreach ($names as $name) {
            $replacement = $this->replace[strtolower(ltrim($name->toString(), '\\'))] ?? null;
            foreach ($replacement === null ? [$name] : array_map(static fn(string $n): Node\Name => new Node\Name\FullyQualified($n), $replacement) as $item) {
                $key = strtolower(ltrim($item->toString(), '\\'));
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $result[]   = $item;
                }
            }
        }

        return $result;
    }

    private function expandType(Node\ComplexType|Node\Identifier|Node\Name|null $type): Node\ComplexType|Node\Identifier|Node\Name|null
    {
        return match (true) {
            $type instanceof Node\Name && isset($this->replace[strtolower(ltrim($type->toString(), '\\'))]) => $this->union($this->expandNames([$type]), false),
            $type instanceof Node\NullableType && $type->type instanceof Node\Name && isset($this->replace[strtolower(ltrim($type->type->toString(), '\\'))])
                => $this->union($this->expandNames([$type->type]), true),
            $type instanceof Node\UnionType => $this->expandUnion($type),
            $type instanceof Node\IntersectionType => $this->expandIntersection($type),
            default => $type,
        };
    }

    private function expandIntersection(Node\IntersectionType $type): Node\ComplexType|Node\Name
    {
        $parts = $this->parts($type);
        if (!array_any($parts, fn(string $p): bool => isset($this->replace[$p]))) {
            return $type;
        }

        $classes = $this->intersectionClasses($parts);

        return $classes === [] ? $type : $this->union(array_map(static fn(string $n): Node\Name => new Node\Name\FullyQualified($n), $classes), false);
    }

    /** @return list<string> lowercase имена частей пересечения */
    private function parts(Node\IntersectionType $type): array
    {
        return array_values(array_map(static fn(Node $p): string => $p instanceof Node\Name ? strtolower(ltrim($p->toString(), '\\')) : '', $type->types));
    }

    /**
     * «Верхние» классы программы, подходящие под все части пересечения.
     *
     * @param  list<string> $parts
     * @return list<string>
     */
    private function intersectionClasses(array $parts): array
    {
        $all = [];
        foreach ($this->classes as $lower => $class) {
            if (!$class instanceof Node\Stmt\Class_ && !$class instanceof Node\Stmt\Enum_ || !$this->inference instanceof TypeInference) {
                continue;
            }

            $ancestors = $this->inference->ancestors($class);
            if (array_all($parts, static fn(string $p): bool => $p === $lower || isset($ancestors[$p]))) {
                $all[$lower] = $class;
            }
        }

        $result = [];
        foreach ($all as $class) {
            $parent = $class instanceof Node\Stmt\Class_ && $class->extends instanceof Node\Name ? strtolower(ltrim($class->extends->toString(), '\\')) : null;
            if (($parent === null || !isset($all[$parent])) && $class->name instanceof Node\Identifier) {
                $result[] = $class->name->toString();
            }
        }

        return $result;
    }

    private function expandUnion(Node\UnionType $type): Node\ComplexType|Node\Identifier|Node\Name
    {
        $parts = [];
        $seen  = [];
        foreach ($type->types as $part) {
            $items = $part instanceof Node\Name ? $this->expandNames([$part]) : [$part];
            foreach ($items as $item) {
                $key = $item instanceof Node\Name || $item instanceof Node\Identifier ? strtolower(ltrim($item->toString(), '\\')) : spl_object_id($item);
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $parts[]    = $item;
                }
            }
        }

        return count($parts) === 1 ? $parts[0] : new Node\UnionType($parts, $type->getAttributes());
    }

    /** @param list<Node\Name> $names */
    private function union(array $names, bool $nullable): Node\ComplexType|Node\Name
    {
        if (count($names) === 1) {
            return $nullable ? new Node\NullableType($names[0]) : $names[0];
        }

        return new Node\UnionType($nullable ? [...$names, new Node\Identifier('null')] : $names);
    }

    /**
     * «Верхние» классы и enum программы, реализующие интерфейс.
     *
     * @param  array<string, Node\Stmt\ClassLike> $classes
     * @return list<string>
     */
    private function implementors(string $interface, array $classes, TypeInference $inference): array
    {
        $all = [];
        foreach ($classes as $lower => $class) {
            if (($class instanceof Node\Stmt\Class_ || $class instanceof Node\Stmt\Enum_) && isset($inference->ancestors($class)[$interface])) {
                $all[$lower] = $class;
            }
        }

        $result = [];
        foreach ($all as $class) {
            $parent = $class instanceof Node\Stmt\Class_ && $class->extends instanceof Node\Name ? strtolower(ltrim($class->extends->toString(), '\\')) : null;
            if (($parent === null || !isset($all[$parent])) && $class->name instanceof Node\Identifier) {
                $result[] = $class->name->toString();
            }
        }

        return $result;
    }

    private function anonymousImplements(Node\Stmt\Class_ $class, string $interface, TypeInference $inference): bool
    {
        return isset($inference->ancestors($class)[$interface]);
    }

    /** Выражение можно вычислить повторно: переменная или цепочка свойств от неё. */
    private function repeatable(Node\Expr $expr): bool
    {
        return $expr instanceof Node\Expr\Variable && is_string($expr->name)
            || $expr instanceof Node\Expr\PropertyFetch && $expr->name instanceof Node\Identifier && $this->repeatable($expr->var)
            || $expr instanceof Node\Expr\ArrayDimFetch && $this->repeatable($expr->var)
                && ($expr->dim instanceof Node\Scalar || $expr->dim instanceof Node\Expr && $this->repeatable($expr->dim));
    }

    /**
     * Имена, которые не заменить: строки, `I::…`, пересечения типов.
     *
     * @return array<string, true>
     */
    private function blockedNames(): array
    {
        $interfaces = [];
        foreach ($this->findNode(Node\Stmt\Interface_::class) as $interface) {
            if ($interface->name instanceof Node\Identifier) {
                $interfaces[$interface->name->toLowerString()] = true;
            }
        }

        $names = [];
        foreach ($this->findNode(Node::class) as $node) {
            if ($node instanceof Node\Scalar\String_) {
                $name = strtolower(ltrim(explode('::', $node->value, 2)[0], '\\'));
                // Имя интерфейса как метка (ключ, сообщение) или в заменяемой проверке
                if (!isset($interfaces[$name]) || !$this->labelOnly($node, 0) && !$this->replaceableCheck($node)) {
                    $names[$name] = true;
                }
            } elseif (
                ($node instanceof Node\Expr\ClassConstFetch || $node instanceof Node\Expr\StaticCall || $node instanceof Node\Expr\StaticPropertyFetch
                    || $node instanceof Node\Expr\New_) && $node->class instanceof Node\Name
            ) {
                $names[strtolower(ltrim($node->class->toString(), '\\'))] = true;
            }
        }

        return $names;
    }

    /** Функции, которые по строке находят класс: строка-имя в них — проверка типа. */
    private const array CLASS_FUNCTIONS = [
        'class_exists', 'interface_exists', 'enum_exists', 'trait_exists', 'is_a', 'is_subclass_of', 'class_implements', 'class_parents',
        'method_exists', 'property_exists', 'get_class_methods', 'call_user_func',
        'call_user_func_array', 'constant', 'defined', 'class_alias', 'get_parent_class', 'spl_autoload_call',
    ];

    /** Функции, результат которых несёт строку дальше. */
    private const array STRING_FUNCTIONS = [
        'sprintf', 'vsprintf', 'implode', 'strtolower', 'strtoupper', 'ucfirst', 'lcfirst', 'trim', 'ltrim', 'rtrim', 'str_replace', 'strtr', 'substr',
    ];

    /**
     * Строка (имя интерфейса) используется только как метка: ключ массива, сравнение,
     * текст сообщения — в том числе через параметры функций и методов программы.
     */
    private function labelOnly(Node\Expr $value, int $depth): bool
    {
        if ($depth > 12) {
            return false;
        }

        $parent = $value->getAttribute('parent');

        return match (true) {
            $parent instanceof Node\ArrayItem => $parent->key === $value,
            $parent instanceof Node\Expr\ArrayDimFetch => $parent->dim === $value,
            $parent instanceof Node\Expr\BinaryOp\Identical, $parent instanceof Node\Expr\BinaryOp\NotIdentical,
            $parent instanceof Node\Expr\BinaryOp\Equal, $parent instanceof Node\Expr\BinaryOp\NotEqual,
            $parent instanceof Node\Stmt\Echo_, $parent instanceof Node\Expr\Isset_ => true,
            $parent instanceof Node\Expr\BinaryOp\Concat, $parent instanceof Node\Expr\Ternary && $parent->cond !== $value,
            $parent instanceof Node\Expr\BinaryOp\Coalesce, $parent instanceof Node\Expr\Cast\String_ => $this->labelOnly($parent, $depth + 1),
            $parent instanceof Node\Expr\Assign => $parent->expr === $value && $this->variableLabelOnly($parent->var, $parent, $depth),
            // `$m .= …` — дописывание: остальные использования переменной проверяются отдельно
            $parent instanceof Node\Expr\AssignOp\Concat => $parent->var === $value || $this->variableLabelOnly($parent->var, $parent, $depth),
            $parent instanceof Node\Arg => $this->argumentLabelOnly($parent, $depth),
            default => false,
        };
    }

    private function variableLabelOnly(Node\Expr $target, Node $at, int $depth): bool
    {
        if (!$target instanceof Node\Expr\Variable || !is_string($target->name)) {
            return false;
        }

        $scope = $at->getAttribute('parent');
        while ($scope instanceof Node && !$scope instanceof Node\FunctionLike) {
            $scope = $scope->getAttribute('parent');
        }

        return $scope instanceof Node\FunctionLike && $this->usesLabelOnly($scope, $target->name, $depth + 1);
    }

    private function usesLabelOnly(Node\FunctionLike $scope, string $name, int $depth): bool
    {
        // Переприсваивание той же переменной (`$m = sprintf($m, …)`) — уже проверяется
        $key = 'var:' . spl_object_id($scope) . ':' . $name;
        if (isset($this->visiting[$key])) {
            return true;
        }

        $this->visiting[$key] = true;
        try {
            return $this->checkUses($scope, $name, $depth);
        } finally {
            unset($this->visiting[$key]);
        }
    }

    private function checkUses(Node\FunctionLike $scope, string $name, int $depth): bool
    {
        foreach (new \PhpParser\NodeFinder()->find($scope->getStmts() ?? [], static fn(Node $n): bool => $n instanceof Node\Expr\Variable && $n->name === $name) as $use) {
            assert($use instanceof Node\Expr\Variable);
            $parent = $use->getAttribute('parent');
            if ($parent instanceof Node\Expr\Assign && $parent->var === $use) {
                continue;
            }

            if ($parent instanceof Node\Expr\ClosureUse || !$this->labelOnly($use, $depth)) {
                return false;
            }
        }

        return true;
    }

    private function argumentLabelOnly(Node\Arg $arg, int $depth): bool
    {
        $call     = $arg->getAttribute('parent');
        $position = $call instanceof Node\Expr\CallLike ? array_search($arg, $call->args, true) : false;
        if (!is_int($position) || $arg->name instanceof Node\Identifier || $call->isPartialFunctionApplication()) {
            return false;
        }

        if ($call instanceof Node\Expr\FuncCall && $call->name instanceof Node\Name && function_exists($call->name->toString())) {
            $function = ltrim($call->name->toLowerString(), '\\');
            if (in_array($function, self::CLASS_FUNCTIONS, true)) {
                return false;
            }

            // Поиск по массиву: метка, если массив — не список классов (class_implements и т.п.)
            if (in_array($function, ['in_array', 'array_search', 'array_key_exists', 'array_keys'], true)) {
                $haystack = ($call->getArgs()[1] ?? null)?->value;

                return !($haystack instanceof Node\Expr\FuncCall && $haystack->name instanceof Node\Name
                    && in_array(ltrim($haystack->name->toLowerString(), '\\'), ['class_implements', 'class_parents', 'get_declared_classes', 'get_declared_interfaces'], true));
            }

            return in_array(ltrim($function, '\\'), self::STRING_FUNCTIONS, true) ? $this->labelOnly($call, $depth + 1) : true;
        }

        // Исключение (встроенное или класс программы с встроенным предком): строка — текст сообщения
        if ($call instanceof Node\Expr\New_ && $call->class instanceof Node\Name && $this->isException(strtolower(ltrim($call->class->toString(), '\\')))) {
            return true;
        }

        $callees = $this->callees($call);
        if ($callees === null || $callees === [] || $arg->unpack) {
            return false;
        }

        $given = count($call->getArgs());
        foreach ($callees as $callee) {
            // Цель, которой не хватит аргументов, этот вызов не вызывает (ArgumentCountError)
            $required = count(array_filter($callee->getParams(), static fn(Node\Param $p): bool => !$p->default instanceof \PhpParser\Node\Expr && !$p->variadic));
            if ($required > $given) {
                continue;
            }

            $params = $callee->getParams();
            $param  = $params[$position] ?? (($last = end($params)) instanceof Node\Param && $last->variadic ? $last : null);
            if (!$param instanceof Node\Param || $param->byRef || !$param->var instanceof Node\Expr\Variable || !is_string($param->var->name)) {
                return false;
            }

            // Повторный заход (рекурсия get() → get()): сам цикл класс не загружает
            $key = spl_object_id($callee) . ':' . $param->var->name;
            if (isset($this->visiting[$key])) {
                continue;
            }

            $this->visiting[$key] = true;
            $label                = $this->usesLabelOnly($callee, $param->var->name, $depth + 1);
            unset($this->visiting[$key]);
            if (!$label) {
                return false;
            }
        }

        return true;
    }

    private function isException(string $class): bool
    {
        $declared = $this->classes[$class] ?? null;
        if (!$declared instanceof Node\Stmt\ClassLike) {
            return is_a($class, \Throwable::class, true);
        }

        return array_any(array_keys($this->inference?->ancestors($declared) ?? []), fn(string $ancestor): bool => !isset($this->classes[$ancestor]) && is_a($ancestor, \Throwable::class, true));
    }

    /** @return list<Node\FunctionLike>|null функции/методы программы с этим именем; null — неизвестно */
    private function callees(mixed $call): ?array
    {
        if ($this->functionsByName === null) {
            $this->functionsByName = [];
            foreach ($this->findNode(Node\Stmt\Function_::class) as $function) {
                $this->functionsByName['fn:' . $function->name->toLowerString()][] = $function;
            }

            foreach ($this->findNode(Node\Stmt\ClassMethod::class) as $method) {
                $this->functionsByName[$method->name->toLowerString()][] = $method;
            }
        }

        // Метод по выводу типа получателя: только методы его классов (предков и наследников)
        if (($call instanceof Node\Expr\MethodCall || $call instanceof Node\Expr\NullsafeMethodCall) && $call->name instanceof Node\Identifier) {
            $types = $this->inference?->typeOf($call->var);
            if ($types !== null && $types !== []) {
                $related = [];
                foreach ($types as $type) {
                    $related[$type] = true;
                    foreach ($this->inference?->subtypes($type) ?? [] as $sub) {
                        $related += $sub->name instanceof Node\Identifier ? [$sub->name->toLowerString() => true] : [];
                        $related += $this->inference->ancestors($sub);
                    }
                }

                return array_values(array_filter(
                    $this->functionsByName[$call->name->toLowerString()] ?? [],
                    static fn(Node\FunctionLike $m): bool => ($owner = $m->getAttribute('parent')) instanceof Node\Stmt\ClassLike
                        && $owner->name instanceof Node\Identifier && isset($related[$owner->name->toLowerString()]),
                ));
            }
        }

        return match (true) {
            $call instanceof Node\Expr\FuncCall && $call->name instanceof Node\Name => $this->functionsByName['fn:' . strtolower(ltrim($call->name->toString(), '\\'))] ?? null,
            ($call instanceof Node\Expr\MethodCall || $call instanceof Node\Expr\NullsafeMethodCall || $call instanceof Node\Expr\StaticCall)
                && $call->name instanceof Node\Identifier => $this->functionsByName[$call->name->toLowerString()] ?? null,
            $call instanceof Node\Expr\New_ => $this->functionsByName['__construct'] ?? null,
            default => null,
        };
    }

    /**
     * Строка во второй позиции `is_subclass_of($x, 'I')` или первой `in_array('I',
     * class_implements($x))` с повторяемым $x — такую проверку enterNode заменяет.
     */
    private function replaceableCheck(Node\Scalar\String_ $string): bool
    {
        $arg  = $string->getAttribute('parent');
        $call = $arg instanceof Node\Arg ? $arg->getAttribute('parent') : null;
        if (!$call instanceof Node\Expr\FuncCall || !$call->name instanceof Node\Name) {
            return false;
        }

        return $this->checkedSubject($call, $string) instanceof \PhpParser\Node\Expr;
    }

    /** Проверяемое выражение проверки «реализует ли $x интерфейс-строку». */
    private function checkedSubject(Node\Expr\FuncCall $call, Node\Scalar\String_ $string): ?Node\Expr
    {
        if ($call->isPartialFunctionApplication()) {
            return null;
        }

        $name = $call->name instanceof Node\Name ? ltrim($call->name->toLowerString(), '\\') : '';
        $args       = $call->getArgs();
        $implements = ($args[1] ?? null)?->value;
        $implements = $implements instanceof Node\Expr\FuncCall && $implements->name instanceof Node\Name
            && ltrim($implements->name->toLowerString(), '\\') === 'class_implements' && !$implements->isPartialFunctionApplication()
            ? ($implements->getArgs()[0] ?? null)?->value : null;
        $subject = match (true) {
            $name === 'is_subclass_of' && ($args[1] ?? null)?->value === $string && count($args) === 2 => $args[0]->value,
            $name === 'in_array' && ($args[0] ?? null)?->value === $string => $implements,
            default => null,
        };

        return $subject instanceof Node\Expr && $this->repeatable($subject) ? $subject : null;
    }

    /**
     * Имена из объявленных типов.
     *
     * @param  array<Node> $nodes
     * @return list<string>
     */
    private function typeNames(array $nodes): array
    {
        $names = [];
        foreach (
            new \PhpParser\NodeFinder()->find($nodes, static fn(Node $n): bool => $n instanceof Node\Param || $n instanceof Node\Stmt\Property
            || $n instanceof Node\Stmt\ClassConst || $n instanceof Node\FunctionLike) as $node
        ) {
            $type = $node instanceof Node\FunctionLike ? $node->getReturnType() : $node->type;
            foreach ($type instanceof Node ? new \PhpParser\NodeFinder()->findInstanceOf($type, Node\Name::class) : [] as $name) {
                $names[] = strtolower(ltrim($name->toString(), '\\'));
            }
        }

        return $names;
    }
}
