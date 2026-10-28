<?php

declare(strict_types=1);

namespace Recombinator\Support;

use PhpParser\Node;
use PhpParser\NodeFinder;

/**
 * Вывод класса значения выражения по объявлениям программы — без выполнения.
 *
 * Тип известен для:
 * - `$this` — класс, где записан код (не в замыкании: его могут привязать к другому объекту);
 * - `new X`, `clone $x`;
 * - параметра — по объявленному (исходному, см. StripLocalClassTypeHintsVisitor) типу,
 *   если переменная не перезаписывается;
 * - локальной переменной — если все её присваивания дают один и тот же класс, а других
 *   способов записи (foreach, list, ссылка, передача по ссылке, `$$`) нет;
 * - `$obj->prop` — по объявленному типу свойства класса $obj;
 * - `$obj->m()`, `X::m()` — по объявленному типу результата метода, одинаковому во всех
 *   классах типа получателя (`static`/`self` — класс получателя / объявления).
 *
 * Результат — список lowercase имён классов или интерфейсов (объединение); null — тип
 * неизвестен. Имена — как записаны в объявлениях (после встраивания — плоские).
 * Узлам нужны атрибуты parent (NodeConnectingVisitor или ParentConnectingVisitor).
 */
final class TypeInference
{
    private const int MAX_DEPTH = 8;

    /** @var array<string, list<string>|null> */
    private array $locals = [];

    /** @var array<int, array{assigns: array<string, array<int, Node\Expr>>, blocked: array<string, true>, dynamic: bool}> */
    private array $indexes = [];

    /** @var array<int, list<string>|null> */
    private array $memo = [];

    /** @var array<string, list<Node\Stmt\ClassMethod>>|null */
    private ?array $methodsByName = null;

    /** @var array<string, list<Node\Stmt\Function_>>|null */
    private ?array $functions = null;

    /** @var array<string, true> функции, тип результата которых сейчас выводится */
    private array $returning = [];

    /** @var list<Node\Stmt\Class_>|null все классы программы (в том числе анонимные) */
    private ?array $allClasses = null;

    /** @var array<int, array<string, true>> */
    private array $ancestry = [];

    /** @var array<string, list<Node\Stmt\Class_>|null> */
    private array $subtypes = [];

    /** @param array<string, Node\Stmt\ClassLike> $classes lowercase имя → объявление */
    /** @var Node\Stmt\Function_|false|null false — ещё не построена */
    private Node\Stmt\Function_|false|null $topLevel = false;

    public function __construct(private readonly array $classes, private readonly array $ast)
    {
    }

    /** @return list<string>|null */
    public function typeOf(Node\Expr $expr, int $depth = 0): ?array
    {
        $key = spl_object_id($expr);
        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        if ($depth > self::MAX_DEPTH) {
            return null;
        }

        $this->memo[$key] = null; // на время вывода — неизвестно
        $result           = $this->compute($expr, $depth);

        return $this->memo[$key] = $result;
    }

    /** @return list<string>|null */
    private function compute(Node\Expr $expr, int $depth): ?array
    {
        $next = $depth + 1;

        return match (true) {
            $expr instanceof Node\Expr\Variable && $expr->name === 'this' => $this->thisType($expr),
            $expr instanceof Node\Expr\Variable && is_string($expr->name) => $this->variableType($expr, $next),
            $expr instanceof Node\Expr\New_ => $expr->class instanceof Node\Name ? $this->named($expr->class, $expr) : null,
            $expr instanceof Node\Expr\Clone_ => $this->typeOf($expr->expr, $next),
            $expr instanceof Node\Expr\Assign => $this->typeOf($expr->expr, $next),
            $expr instanceof Node\Expr\PropertyFetch, $expr instanceof Node\Expr\NullsafePropertyFetch => $this->propertyFetchType($expr, $next),
            $expr instanceof Node\Expr\MethodCall && $this->reflectionInstance($expr) !== null => [$this->reflectionInstance($expr)],
            $expr instanceof Node\Expr\MethodCall, $expr instanceof Node\Expr\NullsafeMethodCall => $this->methodResultType($expr, $next),
            $expr instanceof Node\Expr\StaticCall => $this->staticResultType($expr),
            $expr instanceof Node\Expr\ConstFetch && $expr->name->toLowerString() === 'null' => [],
            $expr instanceof Node\Expr\Ternary => $this->unionOf([$expr->if ?? $expr->cond, $expr->else], $next),
            $expr instanceof Node\Expr\BinaryOp\Coalesce => $this->unionOf([$expr->left, $expr->right], $next),
            $expr instanceof Node\Expr\FuncCall => $this->functionResultType($expr),
            default => null,
        };
    }

    /**
     * @param  list<Node\Expr> $exprs
     * @return list<string>|null
     */
    private function unionOf(array $exprs, int $depth): ?array
    {
        $result = [];
        foreach ($exprs as $expr) {
            $types = $this->typeOf($expr, $depth);
            if ($types === null) {
                return null;
            }

            array_push($result, ...$types);
        }

        return array_values(array_unique($result));
    }

    /** Результат функции программы — по объявленному (исходному) типу. @return list<string>|null */
    private function functionResultType(Node\Expr\FuncCall $call): ?array
    {
        if (!$call->name instanceof Node\Name) {
            return null;
        }

        if ($this->functions === null) {
            $this->functions = [];
            foreach (new NodeFinder()->findInstanceOf($this->ast, Node\Stmt\Function_::class) as $function) {
                $this->functions[$function->name->toLowerString()][] = $function;
            }
        }

        $declared = $this->functions[strtolower(ltrim($call->name->toString(), '\\'))] ?? [];
        if (count($declared) !== 1) {
            return null;
        }

        $type = $declared[0]->getAttribute('originalReturnType') ?? $declared[0]->returnType;
        if ($type === null) {
            return $this->returnedType($declared[0]);
        }

        $bare = $type instanceof Node\NullableType ? $type->type : $type;
        if ($bare instanceof Node\Name && in_array($bare->toLowerString(), ['self', 'static'], true)) {
            $owner = $declared[0]->getAttribute('extractedFrom');

            return is_string($owner) ? [strtolower($owner)] : null;
        }

        return $this->typeNames($type, null);
    }

    /**
     * Тип результата функции без объявленного типа — по её return (не во вложенных
     * функциях); рекурсия — неизвестно.
     *
     * @return list<string>|null
     */
    private function returnedType(Node\Stmt\Function_ $function): ?array
    {
        $key = 'ret:' . spl_object_id($function);
        if (isset($this->returning[$key])) {
            return null;
        }

        $this->returning[$key] = true;
        $result                = [];
        $returns               = new NodeFinder()->find($function->stmts, static function (Node $n) use ($function): bool {
            if (!$n instanceof Node\Stmt\Return_) {
                return false;
            }

            for ($p = $n->getAttribute('parent'); $p instanceof Node; $p = $p->getAttribute('parent')) {
                if ($p instanceof Node\FunctionLike) {
                    return $p === $function;
                }
            }

            return false;
        });
        foreach ($returns as $return) {
            $types = $return instanceof Node\Stmt\Return_ && $return->expr instanceof Node\Expr ? $this->typeOf($return->expr) : null;
            if ($types === null) {
                $result = null;
                break;
            }

            array_push($result, ...$types);
        }

        unset($this->returning[$key]);

        return $result === null || $result === [] ? null : array_values(array_unique($result));
    }

    /**
     * Классы программы с типом $type: сам класс, наследники, реализации интерфейса.
     * null — тип не из программы или его наследует анонимный класс.
     *
     * @return list<Node\Stmt\Class_>|null
     */
    public function subtypes(string $type): ?array
    {
        if (array_key_exists($type, $this->subtypes)) {
            return $this->subtypes[$type];
        }

        $this->allClasses ??= array_values(new NodeFinder()->findInstanceOf($this->ast, Node\Stmt\Class_::class));
        $result             = [];
        foreach ($this->allClasses as $class) {
            if (!isset($this->ancestors($class)[$type])) {
                continue;
            }

            if (!$class->name instanceof Node\Identifier) {
                return $this->subtypes[$type] = null;
            }

            $result[] = $class;
        }

        // Тип не из программы и его никто не замещает (formerTypes) — неизвестно
        if (!isset($this->classes[$type]) && $result === []) {
            return $this->subtypes[$type] = null;
        }

        return $this->subtypes[$type] = $result;
    }

    /**
     * Правило удалило тип (интерфейс, родителя, пустой подкласс), а его объекты теперь —
     * объекты этого класса: объявленные типы (originalType) ещё называют удалённое имя.
     *
     * @param list<string> $types имена
     */
    public static function addFormerTypes(Node\Stmt\ClassLike $class, array $types): void
    {
        $class->setAttribute('formerTypes', array_values(array_unique([...self::formerTypes($class), ...array_map(strtolower(...), $types)])));
    }

    /** @return list<string> lowercase имена удалённых типов, которые замещает класс */
    public static function formerTypes(Node\Stmt\ClassLike $class): array
    {
        $former = $class->getAttribute('formerTypes');

        return is_array($former) ? array_values(array_filter($former, is_string(...))) : [];
    }

    /** @return array<string, true> lowercase имена класса и всех его предков в программе */
    public function ancestors(Node\Stmt\ClassLike $class): array
    {
        $key = spl_object_id($class);
        if (isset($this->ancestry[$key])) {
            return $this->ancestry[$key];
        }

        $result = $class->name instanceof Node\Identifier ? [$class->name->toLowerString() => true] : [];
        $queue  = [$class];
        while ($queue !== []) {
            $current = array_pop($queue);
            foreach (self::formerTypes($current) as $former) {
                $result[$former] = true;
            }

            $parents = match (true) {
                $current instanceof Node\Stmt\Class_ => [$current->extends, ...$current->implements],
                $current instanceof Node\Stmt\Interface_ => $current->extends,
                $current instanceof Node\Stmt\Enum_ => $current->implements,
                default => [],
            };
            foreach ($parents as $parent) {
                if (!$parent instanceof Node\Name) {
                    continue;
                }

                $lower = strtolower(ltrim($parent->toString(), '\\'));
                if (!isset($result[$lower])) {
                    $result[$lower] = true;
                    if (isset($this->classes[$lower])) {
                        $queue[] = $this->classes[$lower];
                    }
                }
            }
        }

        return $this->ancestry[$key] = $result;
    }

    /** Метод по имени: в классе или ближайшем предке программы (трейт не встроен — null). */
    public function lookup(Node\Stmt\Class_ $class, string $name): ?Node\Stmt\ClassMethod
    {
        $seen = [];
        for ($current = $class; $current instanceof Node\Stmt\Class_;) {
            $method = $current->getMethod($name);
            if ($method instanceof Node\Stmt\ClassMethod) {
                return $method;
            }

            if ($current->getTraitUses() !== [] || !$current->extends instanceof Node\Name) {
                return null;
            }

            $parent = strtolower(ltrim($current->extends->toString(), '\\'));
            if (isset($seen[$parent])) {
                return null;
            }

            $seen[$parent] = true;
            $current       = $this->classes[$parent] ?? null;
        }

        return null;
    }

    /** Объявленный (исходный) тип свойства класса или его предков; false — свойства нет. */
    public function propertyType(Node\Stmt\Class_ $class, string $name): Node|false|null
    {
        $seen = [];
        for ($current = $class; $current instanceof Node\Stmt\Class_;) {
            $property = $current->getProperty($name);
            if ($property instanceof Node\Stmt\Property) {
                return $property->getAttribute('originalType') ?? $property->type;
            }

            foreach ($current->getMethod('__construct')->params ?? [] as $param) {
                if ($param->flags !== 0 && $param->var instanceof Node\Expr\Variable && $param->var->name === $name) {
                    return $param->getAttribute('originalType') ?? $param->type;
                }
            }

            $parent = $current->extends instanceof Node\Name ? strtolower(ltrim($current->extends->toString(), '\\')) : null;
            if ($parent === null || isset($seen[$parent])) {
                return false;
            }

            $seen[$parent] = true;
            $current       = $this->classes[$parent] ?? null;
        }

        return false;
    }

    /** @return list<string>|null */
    public function typeNames(?Node $type, ?Node\Stmt\ClassLike $context): ?array
    {
        return match (true) {
            $type instanceof Node\NullableType => $this->typeNames($type->type, $context),
            $type instanceof Node\UnionType => array_reduce(
                $type->types,
                fn(?array $carry, Node $t): ?array => $carry === null || ($names = $this->typeNames($t, $context)) === null ? null : [...$carry, ...$names],
                [],
            ),
            $type instanceof Node\IntersectionType => $this->intersection($type, $context),
            $type instanceof Node\Identifier && in_array($type->toLowerString(), ['null', 'void', 'never'], true) => [],
            ($type instanceof Node\Name || $type instanceof Node\Identifier) && in_array($type->toLowerString(), ['self', 'static'], true)
                => $context instanceof Node\Stmt\ClassLike && $context->name instanceof Node\Identifier ? [$context->name->toLowerString()] : null,
            $type instanceof Node\Name => [strtolower(ltrim($type->toString(), '\\'))],
            default => null,
        };
    }

    /**
     * `A&B`: классы программы, которые подтипы всех известных частей; часть вне
     * программы (внешний интерфейс) не сужает.
     *
     * @return list<string>|null
     */
    private function intersection(Node\IntersectionType $type, ?Node\Stmt\ClassLike $context): ?array
    {
        $result = null;
        foreach ($type->types as $part) {
            $names   = $this->typeNames($part, $context);
            $classes = $names !== null && count($names) === 1 ? $this->subtypes($names[0]) : null;
            if ($classes === null) {
                continue;
            }

            $set    = array_values(array_filter(array_map(
                static fn(Node\Stmt\Class_ $class): ?string => $class->name?->toLowerString(),
                $classes,
            )));
            $result = $result === null ? $set : array_values(array_intersect($result, $set));
        }

        return $result;
    }

    public function enclosingClass(Node $node): ?Node\Stmt\ClassLike
    {
        for ($current = $node->getAttribute('parent'); $current instanceof Node; $current = $current->getAttribute('parent')) {
            if ($current instanceof Node\Stmt\ClassLike) {
                return $current;
            }
        }

        return null;
    }

    public function enclosingFunction(Node $node): ?Node\FunctionLike
    {
        for ($current = $node->getAttribute('parent'); $current instanceof Node; $current = $current->getAttribute('parent')) {
            if ($current instanceof Node\FunctionLike) {
                return $current;
            }
        }

        return null;
    }

    /** @return list<string>|null */
    private function thisType(Node $node): ?array
    {
        $function = $this->enclosingFunction($node);
        $class    = $this->enclosingClass($node);
        if ($function instanceof Node\Expr\Closure || !$class instanceof Node\Stmt\Class_ || !$class->name instanceof Node\Identifier) {
            return null;
        }

        return [$class->name->toLowerString()];
    }

    /** @return list<string>|null */
    private function named(Node\Name $name, ?Node $context = null): ?array
    {
        $lower = $name->toLowerString();
        if (in_array($lower, ['self', 'static', 'parent'], true)) {
            // `new static` — объект этого класса или наследника: тип — сам класс
            $class = $context instanceof Node ? $this->enclosingClass($context) : null;
            // Функция, вынесенная из статического метода (StaticMethodExtractVisitor)
            $extracted = $context instanceof Node ? $this->enclosingFunction($context)?->getAttribute('extractedFrom') : null;
            if (!$class instanceof Node\Stmt\Class_ && is_string($extracted)) {
                $class = $this->classes[strtolower($extracted)] ?? null;
            }

            if (!$class instanceof Node\Stmt\Class_ || !$class->name instanceof Node\Identifier) {
                return null;
            }

            if ($lower !== 'parent') {
                return [$class->name->toLowerString()];
            }

            return $class->extends instanceof Node\Name ? [strtolower(ltrim($class->extends->toString(), '\\'))] : null;
        }

        return [strtolower(ltrim($name->toString(), '\\'))];
    }

    /** @return list<string>|null */
    private function variableType(Node\Expr\Variable $var, int $depth): ?array
    {
        $function = $this->enclosingFunction($var) ?? ($this->enclosingClass($var) instanceof \PhpParser\Node\Stmt\ClassLike ? null : $this->topLevel());
        if (!$function instanceof Node\FunctionLike || !is_string($var->name)) {
            return null;
        }

        $narrowed = $this->narrowed($var, $function, $depth);
        if ($narrowed !== null) {
            return $narrowed;
        }

        $key = spl_object_id($function) . ':' . $var->name;
        if (array_key_exists($key, $this->locals)) {
            return $this->locals[$key];
        }

        $this->locals[$key] = null; // на время вывода: рекурсия через саму себя — неизвестно
        $result             = $this->computeVariable($function, $var->name, $depth);

        return $this->locals[$key] = $result;
    }

    /** `(new \ReflectionClass(C::class))->newInstanceWithoutConstructor()` (гидратация снимка) → C. */
    private function reflectionInstance(Node\Expr\MethodCall $call): ?string
    {
        $new = $call->var;
        $arg = $new instanceof Node\Expr\New_ ? ($new->args[0] ?? null) : null;

        return $call->name instanceof Node\Identifier && $call->name->toLowerString() === 'newinstancewithoutconstructor'
            && $new instanceof Node\Expr\New_ && $new->class instanceof Node\Name && strtolower(ltrim($new->class->toString(), '\\')) === 'reflectionclass'
            && $arg instanceof Node\Arg && $arg->value instanceof Node\Expr\ClassConstFetch && $arg->value->class instanceof Node\Name
            && $arg->value->name instanceof Node\Identifier && $arg->value->name->toLowerString() === 'class'
            ? ltrim($arg->value->class->toString(), '\\')
            : null;
    }

    /** Псевдофункция из кода верхнего уровня; null — переменные могут меняться через global/$GLOBALS. */
    private function topLevel(): ?Node\Stmt\Function_
    {
        if ($this->topLevel === false) {
            $global = new NodeFinder()->findFirst($this->ast, static fn(Node $n): bool => $n instanceof Node\Stmt\Global_
                || $n instanceof Node\Expr\Variable && $n->name === 'GLOBALS');
            $stmts = [];
            foreach ($this->ast as $node) {
                foreach ($node instanceof Node\Stmt\Namespace_ ? $node->stmts : [$node] as $stmt) {
                    if ($stmt instanceof Node\Stmt && !$stmt instanceof Node\Stmt\ClassLike && !$stmt instanceof Node\Stmt\Function_) {
                        $stmts[] = $stmt;
                    }
                }
            }

            // Узлы не переподключаются: parent у инструкций остаётся прежним
            $this->topLevel = $global instanceof Node ? null : new Node\Stmt\Function_('__toplevel', ['stmts' => $stmts]);
        }

        return $this->topLevel;
    }

    /**
     * Тип, суженный проверкой instanceof:
     * - внутри `if ($x instanceof C) {…}`, справа в `$x instanceof C && …`, в ветке «да»
     *   тернарного оператора — если переменная в функции вообще не перезаписывается;
     * - ниже защиты `if (!$x instanceof C) { …; $x = <значение типа C>; }` (без else, на
     *   верхнем уровне тела) — если других записей, кроме этой, нет.
     *
     * @return list<string>|null
     */
    private function narrowed(Node\Expr\Variable $var, Node\FunctionLike $function, int $depth): ?array
    {
        if (!is_string($var->name)) {
            return null;
        }

        $name    = $var->name;
        $index   = $this->functionIndex($function);
        $writes  = count($index['assigns'][$name] ?? []);
        $blocked = $index['dynamic'] || isset($index['blocked'][$name]);
        if ($blocked) {
            return null;
        }

        // Защищённый вызов: `($t = R) instanceof C ? $t->m() : …` — единственная запись в условии
        if ($writes === 1) {
            $child = $var;
            for ($parent = $var->getAttribute('parent'); $parent instanceof Node && $parent !== $function; $parent = $parent->getAttribute('parent')) {
                if (
                    $parent instanceof Node\Expr\Ternary && $child === $parent->if && $parent->cond instanceof Node\Expr\Instanceof_
                    && $parent->cond->expr instanceof Node\Expr\Assign && $parent->cond->expr->var instanceof Node\Expr\Variable
                    && $parent->cond->expr->var->name === $name && $parent->cond->class instanceof Node\Name
                ) {
                    return [strtolower(ltrim($parent->cond->class->toString(), '\\'))];
                }

                $child = $parent;
            }
        }

        // Ветка проверки
        if ($writes === 0) {
            $child = $var;
            for ($parent = $var->getAttribute('parent'); $parent instanceof Node && $parent !== $function; $parent = $parent->getAttribute('parent')) {
                $cond = match (true) {
                    $parent instanceof Node\Stmt\If_ && $child !== $parent->cond && in_array($child, $parent->stmts, true) => $parent->cond,
                    $parent instanceof Node\Expr\BinaryOp\BooleanAnd && $child === $parent->right => $parent->left,
                    $parent instanceof Node\Expr\Ternary && $child === $parent->if => $parent->cond,
                    default => null,
                };
                $type = $cond instanceof Node\Expr ? $this->instanceofType($cond, $name) : null;
                if ($type !== null) {
                    return $type;
                }

                $child = $parent;
            }

            return null;
        }

        // Защита на верхнем уровне тела
        $stmts = $function->getStmts() ?? [];
        $top   = $var;
        for ($parent = $var->getAttribute('parent'); $parent instanceof Node && $parent !== $function; $parent = $parent->getAttribute('parent')) {
            $top = $parent;
        }

        $position = array_search($top, $stmts, true);
        if (!is_int($position)) {
            return null;
        }

        foreach (array_slice($stmts, 0, $position) as $stmt) {
            if (!$stmt instanceof Node\Stmt\If_ || $stmt->elseifs !== [] || $stmt->else instanceof \PhpParser\Node\Stmt\Else_ || !$stmt->cond instanceof Node\Expr\BooleanNot) {
                continue;
            }

            $type = $this->instanceofType($stmt->cond->expr, $name);
            $last = end($stmt->stmts);
            if (
                $type === null || !$last instanceof Node\Stmt\Expression || !$last->expr instanceof Node\Expr\Assign
                || !$last->expr->var instanceof Node\Expr\Variable || $last->expr->var->name !== $name
            ) {
                continue;
            }

            // Единственная запись — эта, и её значение того же типа
            $assigned = $this->typeOf($last->expr->expr, $depth);
            if ($writes === 1 && $assigned !== null && array_diff($assigned, $type) === []) {
                return $type;
            }
        }

        return null;
    }

    /** @return list<string>|null класс из `$x instanceof C` (или одного из `&&`-членов) */
    private function instanceofType(Node\Expr $cond, string $name): ?array
    {
        if ($cond instanceof Node\Expr\BinaryOp\BooleanAnd) {
            return $this->instanceofType($cond->left, $name) ?? $this->instanceofType($cond->right, $name);
        }

        return $cond instanceof Node\Expr\Instanceof_ && $cond->expr instanceof Node\Expr\Variable && $cond->expr->name === $name
            && $cond->class instanceof Node\Name && !in_array($cond->class->toLowerString(), ['self', 'static', 'parent'], true)
            ? [strtolower(ltrim($cond->class->toString(), '\\'))]
            : null;
    }

    /** @return list<string>|null */
    private function computeVariable(Node\FunctionLike $function, string $name, int $depth): ?array
    {
        $types = null;
        foreach ($function->getParams() as $param) {
            if ($param->var instanceof Node\Expr\Variable && $param->var->name === $name) {
                if ($param->byRef || $param->variadic) {
                    return null;
                }

                $types = $this->typeNames($param->getAttribute('originalType') ?? $param->type, $this->enclosingClass($function));
                if ($types === null) {
                    return null;
                }
            }
        }

        if ($function instanceof Node\Expr\Closure && array_any($function->uses, static fn(Node\ClosureUse $u): bool => $u->var->name === $name)) {
            return null;
        }

        $index = $this->functionIndex($function);
        if ($index['dynamic'] || isset($index['blocked'][$name])) {
            return null;
        }

        $assigned = false;
        foreach ($index['assigns'][$name] ?? [] as $value) {
            if ($value instanceof Node\Expr\ConstFetch && $value->name->toLowerString() === 'null') {
                continue; // вызов на null — ошибка; в корректной программе здесь объект
            }

            $type = $this->typeOf($value, $depth);
            if ($type === null) {
                return null;
            }

            $types    = [...$types ?? [], ...$type];
            $assigned = true;
        }

        return $types === null || !$assigned && $types === [] ? $types : array_values(array_unique($types));
    }

    /**
     * Один обход тела функции (без вложенных функций и замыканий): присваивания каждой
     * переменной и переменные, которые меняются иначе (foreach, list, ссылка, …).
     *
     * @return array{assigns: array<string, array<int, Node\Expr>>, blocked: array<string, true>, dynamic: bool}
     */
    private function functionIndex(Node\FunctionLike $function): array
    {
        $key = spl_object_id($function);
        if (isset($this->indexes[$key])) {
            return $this->indexes[$key];
        }

        /** @var array<string, array<int, Node\Expr>> $assigns */
        $assigns = [];
        $blocked = [];
        $dynamic = false;
        $stack   = $function->getStmts() ?? [];
        while ($stack !== []) {
            $node = array_pop($stack);
            if (!$node instanceof Node) {
                continue;
            }

            if ($node instanceof Node\Expr\Assign && $node->var instanceof Node\Expr\Variable && is_string($node->var->name)) {
                $assigns = $this->withAssign($assigns, $node->var->name, $node);
            }

            $binds = match (true) {
                $node instanceof Node\Stmt\Foreach_ => [$node->keyVar, $node->valueVar],
                $node instanceof Node\Expr\AssignRef, $node instanceof Node\Expr\AssignOp => [$node->var],
                $node instanceof Node\Expr\List_ => array_map(static fn(?Node\ArrayItem $i): ?Node => $i?->value, $node->items),
                $node instanceof Node\Stmt\Global_ => $node->vars,
                $node instanceof Node\Stmt\Static_ => array_map(static fn(Node\StaticVar $v): Node => $v->var, $node->vars),
                $node instanceof Node\Stmt\Catch_ => [$node->var],
                $node instanceof Node\Expr\ClosureUse => $node->byRef ? [$node->var] : [],
                $node instanceof Node\Arg => $node->byRef ? [$node->value] : [],
                default => [],
            };
            foreach ($binds as $bound) {
                if ($bound instanceof Node\Expr\Variable && is_string($bound->name)) {
                    $blocked[$bound->name] = true;
                }
            }

            if (
                $node instanceof Node\Expr\Variable && !is_string($node->name)
                || $node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name
                && in_array($node->name->toLowerString(), ['extract', 'get_defined_vars'], true)
            ) {
                $dynamic = true;
            }

            // Вложенные функции и замыкания — своя область (use по ссылке учтён выше)
            if ($node instanceof Node\FunctionLike) {
                if ($node instanceof Node\Expr\Closure) {
                    array_push($stack, ...$node->uses);
                }

                continue;
            }

            foreach ($node->getSubNodeNames() as $sub) {
                $child = $node->{$sub};
                if (is_array($child)) {
                    array_push($stack, ...$child);
                } elseif ($child instanceof Node) {
                    $stack[] = $child;
                }
            }
        }

        return $this->indexes[$key] = ['assigns' => $assigns, 'blocked' => $blocked, 'dynamic' => $dynamic];
    }

    /**
     * @param  array<string, array<int, Node\Expr>> $assigns
     * @return array<string, array<int, Node\Expr>>
     */
    private function withAssign(array $assigns, string $name, Node\Expr\Assign $assign): array
    {
        $assigns[$name][] = $assign->expr;

        return $assigns;
    }

    /** @return list<string>|null */
    private function propertyFetchType(Node\Expr\PropertyFetch|Node\Expr\NullsafePropertyFetch $fetch, int $depth): ?array
    {
        if (!$fetch->name instanceof Node\Identifier) {
            return null;
        }

        $owners = $this->typeOf($fetch->var, $depth);
        if ($owners === null) {
            return null;
        }

        $result = [];
        foreach ($owners as $owner) {
            foreach ($this->concrete($owner) ?? [null] as $class) {
                $type  = $class instanceof Node\Stmt\Class_ ? $this->propertyType($class, $fetch->name->toString()) : false;
                $names = match (true) {
                    $type === false => null,
                    // Без типа: приватное свойство — по присваиваниям в классе
                    !$type instanceof \PhpParser\Node && $class instanceof Node\Stmt\Class_ => $this->assignedPropertyType($class, $fetch->name->toString(), $depth),
                    default => $this->typeNames($type, $class),
                };
                if ($names === null) {
                    return null;
                }

                array_push($result, ...$names);
            }
        }

        return array_values(array_unique($result));
    }

    /**
     * Тип приватного свойства без объявленного типа — объединение типов всех
     * `$this->p = …` в объявившем его классе (`null` типа не добавляет).
     *
     * @return list<string>|null
     */
    private function assignedPropertyType(Node\Stmt\Class_ $class, string $name, int $depth): ?array
    {
        $declaring = $class;
        while ($declaring instanceof Node\Stmt\Class_ && !$declaring->getProperty($name) instanceof Node\Stmt\Property) {
            $declaring = $declaring->extends instanceof Node\Name ? $this->classes[strtolower(ltrim($declaring->extends->toString(), '\\'))] ?? null : null;
        }

        $property = $declaring instanceof Node\Stmt\Class_ ? $declaring->getProperty($name) : null;
        if (!$property instanceof Node\Stmt\Property || !$property->isPrivate() || !$declaring instanceof Node\Stmt\Class_) {
            return null;
        }

        $result = [];
        foreach (new NodeFinder()->findInstanceOf($declaring->stmts, Node\Expr\Assign::class) as $assign) {
            if (
                !$assign->var instanceof Node\Expr\PropertyFetch || !$assign->var->name instanceof Node\Identifier
                || $assign->var->name->toString() !== $name
            ) {
                continue;
            }

            if (!$assign->var->var instanceof Node\Expr\Variable || $assign->var->var->name !== 'this') {
                return null; // запись в чужой объект того же класса
            }

            $types = $this->typeOf($assign->expr, $depth + 1);
            if ($types === null) {
                return null;
            }

            array_push($result, ...$types);
        }

        return $result === [] ? null : array_values(array_unique($result));
    }

    /** @return list<string>|null */
    private function methodResultType(Node\Expr\MethodCall|Node\Expr\NullsafeMethodCall $call, int $depth): ?array
    {
        if (!$call->name instanceof Node\Identifier) {
            return null;
        }

        $receivers = $this->typeOf($call->var, $depth);
        if ($receivers === null) {
            return null;
        }

        $result = [];
        foreach ($receivers as $receiver) {
            // Тип получателя из программы удалён (интерфейс, родитель): результат — по
            // одноимённым методам программы, если все они объявляют один и тот же тип
            if ($this->concrete($receiver) === null && !isset($this->classes[$receiver])) {
                $agreed = $this->agreedResultType($call->name->toLowerString());
                if ($agreed === null) {
                    return null;
                }

                array_push($result, ...$agreed);
                continue;
            }

            foreach ($this->concrete($receiver) ?? [null] as $class) {
                $method = $class instanceof Node\Stmt\Class_ ? $this->lookup($class, $call->name->toLowerString()) : null;
                if (!$method instanceof Node\Stmt\ClassMethod) {
                    return null;
                }

                $declared = $method->getAttribute('originalReturnType') ?? $method->returnType;
                $bare     = $declared instanceof Node\NullableType ? $declared->type : $declared;
                $names    = $bare instanceof Node\Name && $bare->toLowerString() === 'static' && $class->name instanceof Node\Identifier
                    ? [$class->name->toLowerString()]
                    : $this->typeNames($declared, $this->ownerOf($method));
                if ($names === null) {
                    return null;
                }

                array_push($result, ...$names);
            }
        }

        return array_values(array_unique($result));
    }

    /** @return list<string>|null */
    private function staticResultType(Node\Expr\StaticCall $call): ?array
    {
        if (!$call->class instanceof Node\Name || !$call->name instanceof Node\Identifier) {
            return null;
        }

        $lower = $call->class->toLowerString();
        $class = match ($lower) {
            'self', 'static' => $this->enclosingClass($call),
            'parent' => null,
            default => $this->classes[ltrim($lower, '\\')] ?? null,
        };
        if (!$class instanceof Node\Stmt\Class_ || $lower === 'static') {
            return null;
        }

        $method = $this->lookup($class, $call->name->toLowerString());
        if (!$method instanceof Node\Stmt\ClassMethod) {
            return null;
        }

        $declared = $method->getAttribute('originalReturnType') ?? $method->returnType;
        $bare     = $declared instanceof Node\NullableType ? $declared->type : $declared;

        // static у класса с наследниками — неизвестно, какой именно
        if ($bare instanceof Node\Name && $bare->toLowerString() === 'static') {
            return $class->name instanceof Node\Identifier && count($this->subtypes($class->name->toLowerString()) ?? []) <= 1 ? [$class->name->toLowerString()] : null;
        }

        return $this->typeNames($declared, $this->ownerOf($method));
    }

    /** @return list<string>|null объявленный тип результата, одинаковый у всех методов с этим именем */
    private function agreedResultType(string $method): ?array
    {
        if ($this->methodsByName === null) {
            $this->methodsByName = [];
            foreach (new NodeFinder()->findInstanceOf($this->ast, Node\Stmt\ClassMethod::class) as $declared) {
                $this->methodsByName[$declared->name->toLowerString()][] = $declared;
            }
        }

        $result = null;
        foreach ($this->methodsByName[$method] ?? [] as $declared) {
            $names = $this->typeNames($declared->getAttribute('originalReturnType') ?? $declared->returnType, $this->ownerOf($declared));
            if ($names === null || $names === [] || $result !== null && $names !== $result) {
                return null;
            }

            $result = $names;
        }

        return $result;
    }

    /**
     * Конкретные (не абстрактные) классы программы с этим типом.
     *
     * @return list<Node\Stmt\Class_>|null
     */
    private function concrete(string $type): ?array
    {
        $classes = $this->subtypes($type);

        return $classes === null ? null : array_values(array_filter($classes, static fn(Node\Stmt\Class_ $c): bool => !$c->isAbstract()));
    }

    private function ownerOf(Node\Stmt\ClassMethod $method): ?Node\Stmt\ClassLike
    {
        $parent = $method->getAttribute('parent');

        return $parent instanceof Node\Stmt\ClassLike ? $parent : null;
    }
}
