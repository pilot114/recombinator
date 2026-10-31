<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use Recombinator\Support\TypeInference;
use Recombinator\Support\VisibilityConsistency;

/**
 * Встраивает функцию с единственным местом вызова в инструкцию вызова:
 *
 *   function getConnection(array $params, $config = null) {        $params1 = ['driver' => 'pdo_sqlite'];
 *       $driver = createDriver($params['driver']);                   $config1 = null;
 *       return new Connection($params, $driver, $config);       →    $driver1 = createDriver($params1['driver']);
 *   }                                                                $result1 = new Connection($params1, $driver1, $config1);
 *   $em = new EntityManager(getConnection(['driver' => …]), $c);     $em = new EntityManager($result1, $c);
 *
 * Тело ставится перед инструкцией с вызовом, если вызов в ней вычисляется первым: до
 * него — только литералы, константы и локальные переменные (их тело функции изменить не
 * может), и вызов не в условно вычисляемой части (`&&`, `?:`, `??`, ветки match, условие
 * цикла). Параметры получают значения аргументов в исходном порядке; скалярный параметр —
 * с приведением `(int)`/`(string)`/…, которое для допустимых значений совпадает с
 * приведением при вызове. Переменные тела переименовываются в уникальные. `return`
 * становится присваиванием результата; ранний return — `break` из обёртки
 * `do { … } while (false)` (с учётом вложенных циклов).
 *
 * Не встраиваются: рекурсивные функции, генераторы, функции со `static`/`global`,
 * параметрами по ссылке и вариативными, `func_get_args()`/`compact()`/`extract()`,
 * функции, упомянутые строкой (callable), объявленные не на верхнем уровне.
 */
#[VisitorMeta('Встраивание функции с одним вызовом на уровне инструкций', targets: [Node\Stmt\Function_::class, Node\Stmt\ClassMethod::class])]
class CallSiteInlinerVisitor extends BaseVisitor
{
    /** @var array<string, Node\Stmt\Function_> lowercase имя → функция с единственным вызовом */
    private array $candidates = [];

    /** @var array<int, array{Node\Stmt, list<Node\Stmt>}> spl_object_id(инструкции) → её пролог */
    private array $preludes = [];

    /** @var array<int, array<string, true>> имена переменных, занятые в области видимости */
    private array $taken = [];

    private int $counter = 0;

    /** Короткое тело (инструкций, включая вложенные) встраивается в каждое из немногих мест вызова. */
    private const int SMALL_BODY = 5;

    private const int SMALL_CALLS = 6;

    /** @var list<Node\Expr> выражения, вычисляемые раньше вызова, — во временные переменные */
    private array $spill = [];

    /** @var array<string, true> имена вызываемых методов (lowercase) и функций (`fn:имя`) */
    private array $calledNames = [];

    /** В программе есть вызов метода по вычисленному имени — любой метод может быть жив. */
    private bool $dynamicCalls = false;

    /** @var array<string, int> lowercase имя метода → число вызовов по имени */
    private array $methodCalls = [];

    /** @var array<string, Node\Stmt\ClassLike> */
    private array $classes = [];

    private ?TypeInference $inference = null;

    /** @var array<string, list<Node\Stmt\ClassMethod>>|null */
    private ?array $methodsByName = null;

    /** @var array<string, Node\Stmt\Function_> функции программы по lowercase имени */
    private array $functionsByName = [];

    /** Видимость членов расширена — выровнять у наследников (afterTraverse) */
    private bool $publicized = false;

    #[\Override]
    public function afterTraverse(array $nodes): ?array
    {
        if ($this->publicized) {
            VisibilityConsistency::apply($nodes);
        }

        return null;
    }

    #[\Override]
    public function beforeTraverse(array $nodes): ?array
    {
        parent::beforeTraverse($nodes);
        $this->candidates    = [];
        $this->methodsByName = null;
        $this->preludes      = [];
        $this->taken        = [];
        $this->dynamicCalls = false;
        if ($this->hasPendingIncludes()) {
            return null;
        }

        $functions         = [];
        $calls             = [];
        $strings           = [];
        $this->calledNames = [];
        $this->methodCalls = [];
        $this->classes     = [];
        $this->publicized  = false;
        foreach ($this->findNode(Node\Stmt\ClassLike::class) as $class) {
            if ($class->name instanceof Node\Identifier) {
                $this->classes[$class->name->toLowerString()] = $class;
            }
        }

        $this->inference = new TypeInference($this->classes, $nodes);
        foreach ($this->findNode(Node::class) as $node) {
            if ($node instanceof Node\Stmt\Function_) {
                $functions[$node->name->toLowerString()][] = $node;
            } elseif ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name) {
                $calls[strtolower(ltrim($node->name->toString(), '\\'))][] = $node;
                $this->calledNames['fn:' . strtolower(ltrim($node->name->toString(), '\\'))] = true;
            } elseif ($node instanceof Node\Scalar\String_) {
                $strings[strtolower(ltrim($node->value, '\\'))] = true;
                // 'C::m', 'm' — callable: метод или функция может быть вызван по строке
                $tail = strtolower(substr((string) strrchr(':' . $node->value, ':'), 1));
                $this->calledNames[$tail] = true;
                $this->calledNames['fn:' . strtolower(ltrim($node->value, '\\'))] = true;
            } elseif (
                ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\NullsafeMethodCall || $node instanceof Node\Expr\StaticCall)
            ) {
                if ($node->name instanceof Node\Identifier) {
                    $this->calledNames[$node->name->toLowerString()] = true;
                    $this->methodCalls[$node->name->toLowerString()] = ($this->methodCalls[$node->name->toLowerString()] ?? 0) + 1;
                } else {
                    $this->dynamicCalls = true;
                }
            }
        }

        $this->functionsByName = array_map(static fn(array $d): Node\Stmt\Function_ => $d[0], $functions);
        foreach ($functions as $name => $declarations) {
            $function = $declarations[0];
            if (
                count($declarations) !== 1 || !$this->fewCalls(count($calls[$name] ?? []), $function) || isset($strings[$name])
                || !in_array($function, $nodes, true) || !$this->inlinable($function)
            ) {
                continue;
            }

            $this->candidates[$name] = $function;
        }

        return null;
    }

    public function enterNode(Node $node): ?Node
    {
        if ($node instanceof Node\Expr\MethodCall) {
            return $this->enterMethodCall($node);
        }

        if ($node instanceof Node\Expr\StaticCall) {
            return $this->enterStaticCall($node);
        }

        if (!$node instanceof Node\Expr\FuncCall || !$node->name instanceof Node\Name || $node->isFirstClassCallable()) {
            return null;
        }

        $function = $this->candidates[strtolower(ltrim($node->name->toString(), '\\'))] ?? null;
        $caller   = $this->enclosingFunction($node);
        if (!$function instanceof Node\Stmt\Function_ || $caller === $function || $this->dead($caller)) {
            return null;
        }

        $statement = $this->hoistTarget($node);
        if (!$statement instanceof Node\Stmt) {
            return null;
        }

        $inlined = $this->inline($function, $node);
        if ($inlined === null) {
            return null;
        }

        [$prelude, $result] = $inlined;
        $prelude = [...$this->spilled($node), ...$prelude];
        // Несколько вызовов в одной инструкции — прологи по порядку вычисления
        $previous = $this->preludes[spl_object_id($statement)][1] ?? [];
        $this->preludes[spl_object_id($statement)] = [$statement, [...$previous, ...$prelude]];

        return $result;
    }

    /** Вызов метода с единственной целью (по выводу типа получателя) и единственным местом вызова. */
    private function enterMethodCall(Node\Expr\MethodCall $call): ?Node
    {
        if (!$call->name instanceof Node\Identifier || $call->isFirstClassCallable() || ($this->methodCalls[$call->name->toLowerString()] ?? 0) === 0) {
            return null;
        }

        $target = $this->methodTarget($call);
        if ($target === null) {
            return null;
        }

        [$method, $declaring, $concrete] = $target;
        if (!$this->fewCalls($this->methodCalls[$call->name->toLowerString()] ?? 0, $method)) {
            return null;
        }

        $caller = $this->enclosingFunction($call);
        if ($caller === $method || $this->dead($caller) || !$this->inlinable($method, $concrete) || !$this->propertiesAccessible($method, $declaring, $call)) {
            return null;
        }

        $statement = $this->hoistTarget($call);
        if (!$statement instanceof Node\Stmt) {
            return null;
        }

        $inlined = $this->inline($method, $call, [$declaring, $concrete]);
        if ($inlined === null) {
            return null;
        }

        [$prelude, $result] = $inlined;
        $prelude = [...$this->spilled($call), ...$prelude];
        $previous = $this->preludes[spl_object_id($statement)][1] ?? [];
        $this->preludes[spl_object_id($statement)] = [$statement, [...$previous, ...$prelude]];

        return $result;
    }

    /**
     * Статический вызов `C::m()` (или self::, static:: в классе без наследников): тело метода
     * (своего или унаследованного), где static — вызванный класс C, self — класс объявления.
     */
    private function enterStaticCall(Node\Expr\StaticCall $call): ?Node
    {
        if (!$call->name instanceof Node\Identifier || !$call->class instanceof Node\Name || $call->isFirstClassCallable()) {
            return null;
        }

        $called = $this->calledClass($call);
        $method = $called instanceof Node\Stmt\Class_ ? $this->inference?->lookup($called, $call->name->toString()) : null;
        $declaring = $method?->getAttribute('parent');
        if (
            !$called instanceof Node\Stmt\Class_ || !$method instanceof Node\Stmt\ClassMethod || !$method->isStatic() || $method->stmts === null
            || !$declaring instanceof Node\Stmt\Class_ || !$this->fewCalls($this->methodCalls[$call->name->toLowerString()] ?? 0, $method)
        ) {
            return null;
        }

        $caller = $this->enclosingFunction($call);
        if ($caller === $method || $this->dead($caller) || !$this->inlinable($method, $called)) {
            return null;
        }

        $statement = $this->hoistTarget($call);
        if (!$statement instanceof Node\Stmt) {
            return null;
        }

        $inlined = $this->inline($method, $call, [$declaring, $called]);
        if ($inlined === null) {
            return null;
        }

        [$prelude, $result] = $inlined;
        $prelude = [...$this->spilled($call), ...$prelude];
        $previous = $this->preludes[spl_object_id($statement)][1] ?? [];
        $this->preludes[spl_object_id($statement)] = [$statement, [...$previous, ...$prelude]];

        return $result;
    }

    /** Класс, на котором вызван статический метод. */
    private function calledClass(Node\Expr\StaticCall $call): ?Node\Stmt\Class_
    {
        assert($call->class instanceof Node\Name);
        $lower = $call->class->toLowerString();
        if ($lower === 'parent') {
            return null; // parent:: передаёт позднее связывание — static был бы другим
        }

        if ($lower === 'self' || $lower === 'static') {
            $class = $this->inference?->enclosingClass($call);
            if (!$class instanceof Node\Stmt\Class_ || $lower === 'static' && $this->hasSubclasses($class)) {
                return null;
            }

            return $class;
        }

        $class = $this->classes[ltrim($lower, '\\')] ?? null;

        return $class instanceof Node\Stmt\Class_ ? $class : null;
    }

    private function hasSubclasses(Node\Stmt\Class_ $class): bool
    {
        $name = strtolower((string) $class->name);

        return array_any($this->classes, static fn(Node\Stmt\ClassLike $c): bool => $c instanceof Node\Stmt\Class_
            && $c->extends instanceof Node\Name && strtolower(ltrim($c->extends->toString(), '\\')) === $name);
    }

    /**
     * Метод, который вызовет `$x->m()`: для всех возможных классов получателя — одна и
     * та же реализация; [метод, класс объявления, конкретный класс получателя или null].
     *
     * @return array{Node\Stmt\ClassMethod, Node\Stmt\Class_, ?Node\Stmt\Class_}|null
     */
    private function methodTarget(Node\Expr\MethodCall $call): ?array
    {
        $types = $this->inference?->typeOf($call->var);
        if ($types === null || $types === [] || !$call->name instanceof Node\Identifier) {
            return null;
        }

        $method   = null;
        $concrete = [];
        foreach ($types as $type) {
            foreach ($this->inference?->subtypes($type) ?? [null] as $class) {
                if (!$class instanceof Node\Stmt\Class_) {
                    return null;
                }

                if ($class->isAbstract()) {
                    continue;
                }

                $found = $this->inference?->lookup($class, $call->name->toString());
                if (!$found instanceof Node\Stmt\ClassMethod || $method instanceof Node\Stmt\ClassMethod && $found !== $method) {
                    return null;
                }

                $method     = $found;
                $concrete[] = $class;
            }
        }

        $declaring = $method?->getAttribute('parent');
        if (
            !$method instanceof Node\Stmt\ClassMethod || !$declaring instanceof Node\Stmt\Class_ || $method->isAbstract() || $method->stmts === null
            // статический метод через объект: получатель не вычисляется в теле — только переменная без побочных эффектов
            || $method->isStatic() && !$call->var instanceof Node\Expr\Variable
        ) {
            return null;
        }

        return [$method, $declaring, count(array_unique($concrete, SORT_REGULAR)) === 1 ? $concrete[0] : null];
    }

    public function leaveNode(Node $node): int|Node|array|null
    {
        $prelude = $node instanceof Node\Stmt ? $this->preludes[spl_object_id($node)] ?? null : null;
        if ($prelude !== null) {
            unset($this->preludes[spl_object_id($node)]);

            return [...$prelude[1], $node];
        }

        return parent::leaveNode($node);
    }

    /** Встраивать: единственный вызов или короткое тело при немногих вызовах (копии малы). */
    private function fewCalls(int $calls, Node\Stmt\Function_|Node\Stmt\ClassMethod $function): bool
    {
        if ($calls === 1) {
            return true;
        }

        $size = count($this->findNode(Node\Stmt::class, $function->stmts ?? []));

        // Тело из одного return — копия не длиннее вызова: мест может быть больше
        return $calls >= 1 && ($calls <= self::SMALL_CALLS && $size <= self::SMALL_BODY
            || $calls <= 3 * self::SMALL_CALLS && $size === 1 && ($function->stmts[0] ?? null) instanceof Node\Stmt\Return_);
    }

    /**
     * Метод или функция, которые никто не вызывает (их удалит очистка): встраивать в
     * них незачем — тело осталось бы в мёртвом коде.
     */
    private function dead(?Node\FunctionLike $caller): bool
    {
        if ($caller instanceof Node\Stmt\Function_) {
            return !isset($this->calledNames['fn:' . $caller->name->toLowerString()]);
        }

        if ($caller instanceof Node\Stmt\ClassMethod) {
            $name = $caller->name->toLowerString();

            return !$this->dynamicCalls && !str_starts_with($name, '__') && !isset($this->calledNames[$name]) && $caller->attrGroups === [];
        }

        return false;
    }

    /**
     * Тело можно перенести в место вызова (см. описание класса). Метод: `$this` — получатель;
     * нельзя `parent::`, замыкание с `$this`, `static::` при неизвестном конкретном классе,
     * запись в readonly-свойство.
     */
    private function inlinable(Node\Stmt\Function_|Node\Stmt\ClassMethod $function, ?Node\Stmt\Class_ $concrete = null): bool
    {
        $isMethod = $function instanceof Node\Stmt\ClassMethod;
        if ($isMethod && !$this->methodBodyAllowed($function, $concrete)) {
            return false;
        }

        if ($function->byRef) {
            return false;
        }

        foreach ($function->params as $param) {
            if ($param->byRef || $param->variadic || !$param->var instanceof Node\Expr\Variable || !is_string($param->var->name)) {
                return false;
            }
        }

        $self = $function->name->toLowerString();
        foreach ($this->findNode(Node::class, $function->stmts) as $node) {
            $forbidden = match (true) {
                $node instanceof Node\Expr\Yield_, $node instanceof Node\Expr\YieldFrom, $node instanceof Node\Stmt\Static_,
                $node instanceof Node\Stmt\Global_, $node instanceof Node\Scalar\MagicConst, $node instanceof Node\Expr\Eval_,
                $node instanceof Node\Stmt\Goto_, $node instanceof Node\Stmt\Label, $node instanceof Node\Stmt\Function_,
                $node instanceof Node\Stmt\ClassLike => true,
                $node instanceof Node\Expr\Variable => !is_string($node->name) || $node->name === 'GLOBALS' || $node->name === 'this' && !$isMethod,
                $node instanceof Node\Expr\FuncCall => $node->name instanceof Node\Name && (
                    in_array($node->name->toLowerString(), ['func_get_args', 'func_get_arg', 'func_num_args', 'compact', 'extract', 'get_defined_vars'], true)
                    || strtolower(ltrim($node->name->toString(), '\\')) === $self
                ),
                default => false,
            };
            if ($forbidden) {
                return false;
            }
        }

        return true;
    }

    /**
     * Инструкция, перед которой можно поставить тело: вызов в ней вычисляется первым.
     */
    private function hoistTarget(Node\Expr\FuncCall|Node\Expr\MethodCall|Node\Expr\StaticCall $call): ?Node\Stmt
    {
        $this->spill = [];
        $child       = $call;
        for ($parent = $call->getAttribute('parent'); $parent instanceof Node; $parent = $parent->getAttribute('parent')) {
            if ($parent instanceof Node\Stmt) {
                return $this->statementAllows($parent, $child) ? $parent : null;
            }

            if (!$this->evaluatedFirst($parent, $child)) {
                return null;
            }

            $child = $parent;
        }

        return null;
    }

    /** Инструкция, где выражение вычисляется один раз и до остального. */
    private function statementAllows(Node\Stmt $statement, Node $child): bool
    {
        if (!$this->inStatementList($statement)) {
            return false;
        }

        return match (true) {
            $statement instanceof Node\Stmt\Expression, $statement instanceof Node\Stmt\Return_ => true,
            $statement instanceof Node\Stmt\If_ => $statement->cond === $child,
            $statement instanceof Node\Stmt\Switch_ => $statement->cond === $child,
            $statement instanceof Node\Stmt\Foreach_ => $statement->expr === $child,
            $statement instanceof Node\Stmt\Echo_ => $this->previousPure($statement->exprs, $child),
            default => false,
        };
    }

    /** Инструкция стоит в списке инструкций (а не, скажем, в объявлении свойства). */
    private function inStatementList(Node\Stmt $statement): bool
    {
        $parent = $statement->getAttribute('parent');
        if ($parent === null) {
            return in_array($statement, $this->ast, true);
        }

        foreach ($parent->getSubNodeNames() as $name) {
            if (is_array($parent->$name) && in_array($statement, $parent->$name, true)) {
                return $name === 'stmts';
            }
        }

        return false;
    }

    private function methodBodyAllowed(Node\Stmt\ClassMethod $method, ?Node\Stmt\Class_ $concrete): bool
    {
        $declaring = $method->getAttribute('parent');
        $self      = $method->name->toLowerString();
        foreach ($this->findNode(Node::class, $method->stmts ?? []) as $node) {
            $forbidden = match (true) {
                $node instanceof Node\Name => $node->toLowerString() === 'parent' || $node->toLowerString() === 'static' && !$concrete instanceof Node\Stmt\Class_,
                // Замыкание в теле связывается с $this места вызова
                $node instanceof Node\Expr\Closure && !$node->static, $node instanceof Node\Expr\ArrowFunction && !$node->static
                    => array_any($this->findNode(Node\Expr\Variable::class, [$node]), static fn(Node\Expr\Variable $v): bool => $v->name === 'this'),
                // Рекурсия
                $node instanceof Node\Expr\MethodCall, $node instanceof Node\Expr\StaticCall => $node->name instanceof Node\Identifier && $node->name->toLowerString() === $self,
                $node instanceof Node\Expr\Assign, $node instanceof Node\Expr\AssignOp => $this->readonlyTarget($node->var, $declaring),
                default => false,
            };
            if ($forbidden) {
                return false;
            }
        }

        return $declaring instanceof Node\Stmt\Class_ && !$this->membersClash($method, $declaring);
    }

    /** Запись в readonly-свойство вне класса — ошибка. */
    private function readonlyTarget(Node\Expr $target, mixed $class): bool
    {
        while ($target instanceof Node\Expr\ArrayDimFetch) {
            $target = $target->var;
        }

        if (!$target instanceof Node\Expr\PropertyFetch || !$target->name instanceof Node\Identifier || !$class instanceof Node\Stmt\Class_) {
            return false;
        }

        if ($class->isReadonly()) {
            return true;
        }

        $property = $class->getProperty($target->name->toString());
        if ($property instanceof Node\Stmt\Property) {
            return $property->isReadonly();
        }

        foreach ($class->getMethod('__construct')->params ?? [] as $param) {
            if ($param->var instanceof Node\Expr\Variable && $param->var->name === $target->name->toString()) {
                return ($param->flags & \PhpParser\Modifiers::READONLY) !== 0;
            }
        }

        return false;
    }

    /**
     * Закрытые члены, к которым обращается тело, станут публичными (вызов — из другого
     * класса). Нельзя, если у наследника есть одноимённый член: закрытый член не
     * наследуется, а публичный превратил бы его в переопределение.
     */
    private function membersClash(Node\Stmt\ClassMethod $method, Node\Stmt\Class_ $declaring): bool
    {
        $names = $this->memberNames($method);
        foreach ($this->classes as $class) {
            if (!$class instanceof Node\Stmt\Class_ || $class === $declaring || !isset($this->inference?->ancestors($class)[strtolower((string) $declaring->name)])) {
                continue;
            }

            foreach ($class->stmts as $stmt) {
                foreach ($this->declaredNames($stmt) as $name) {
                    if (isset($names[$name]) && $this->isPrivateIn($declaring, $name)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /** @return array<string, true> `$свойство`, `fn:метод`, `const:имя` — члены, названные в теле */
    private function memberNames(Node\Stmt\ClassMethod $method): array
    {
        $names = [];
        foreach ($this->findNode(Node::class, $method->stmts ?? []) as $node) {
            if (($node instanceof Node\Expr\PropertyFetch || $node instanceof Node\Expr\NullsafePropertyFetch) && $node->name instanceof Node\Identifier) {
                $names['$' . $node->name->toString()] = true;
            } elseif ($node instanceof Node\Expr\StaticPropertyFetch && $node->name instanceof Node\VarLikeIdentifier) {
                $names['$' . $node->name->toString()] = true;
            } elseif (($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\StaticCall || $node instanceof Node\Expr\NullsafeMethodCall) && $node->name instanceof Node\Identifier) {
                $names['fn:' . $node->name->toLowerString()] = true;
            } elseif ($node instanceof Node\Expr\ClassConstFetch && $node->name instanceof Node\Identifier) {
                $names['const:' . $node->name->toString()] = true;
            }
        }

        return $names;
    }

    /** @return list<string> */
    private function declaredNames(Node\Stmt $stmt): array
    {
        return match (true) {
            $stmt instanceof Node\Stmt\Property => array_map(static fn(Node\PropertyItem $p): string => '$' . $p->name->toString(), $stmt->props),
            $stmt instanceof Node\Stmt\ClassConst => array_map(static fn(Node\Const_ $c): string => 'const:' . $c->name->toString(), $stmt->consts),
            $stmt instanceof Node\Stmt\ClassMethod => ['fn:' . $stmt->name->toLowerString()],
            default => [],
        };
    }

    private function isPrivateIn(Node\Stmt\Class_ $class, string $name): bool
    {
        foreach ($class->stmts as $stmt) {
            if (in_array($name, $this->declaredNames($stmt), true) && property_exists($stmt, 'flags')) {
                return ($stmt->flags & \PhpParser\Modifiers::PRIVATE) !== 0;
            }
        }

        return false;
    }

    /**
     * Свойства экземпляра, к которым обращается тело, доступны из места вызова. Их
     * видимость не расширяется: она наблюдаема — `(array) $obj` даёт ключ "\0*\0key",
     * json_encode/serialize/var_export выводят только публичные свойства.
     */
    private function propertiesAccessible(Node\Stmt\ClassMethod $method, Node\Stmt\Class_ $declaring, Node $call): bool
    {
        $caller = $this->inference?->enclosingClass($call);
        foreach (array_keys($this->memberNames($method)) as $name) {
            if (!str_starts_with($name, '$')) {
                continue;
            }

            [$owner, $flags] = $this->propertyDeclaration($declaring, substr($name, 1)) ?? [null, null];
            if (
                !$owner instanceof Node\Stmt\Class_ || ($flags & (\PhpParser\Modifiers::PRIVATE | \PhpParser\Modifiers::PROTECTED)) === 0
                || ($flags & \PhpParser\Modifiers::STATIC) !== 0
            ) {
                continue;
            }

            if (!$caller instanceof Node\Stmt\Class_) {
                return false;
            }

            if (($flags & \PhpParser\Modifiers::PRIVATE) !== 0 ? $caller !== $owner : !$this->related($caller, $owner)) {
                return false;
            }
        }

        return true;
    }

    /** @return array{Node\Stmt\Class_, int}|null класс, объявивший свойство, и его флаги */
    private function propertyDeclaration(Node\Stmt\Class_ $class, string $name): ?array
    {
        for ($current = $class; $current instanceof Node\Stmt\Class_;) {
            $property = $current->getProperty($name);
            if ($property instanceof Node\Stmt\Property) {
                return [$current, $property->flags];
            }

            foreach ($current->getMethod('__construct')->params ?? [] as $param) {
                if ($param->flags !== 0 && $param->var instanceof Node\Expr\Variable && $param->var->name === $name) {
                    return [$current, $param->flags];
                }
            }

            $current = $current->extends instanceof Node\Name ? $this->classes[strtolower(ltrim($current->extends->toString(), '\\'))] ?? null : null;
        }

        return null;
    }

    private function related(Node\Stmt\Class_ $a, Node\Stmt\Class_ $b): bool
    {
        return isset($this->inference?->ancestors($a)[strtolower((string) $b->name)]) || isset($this->inference?->ancestors($b)[strtolower((string) $a->name)]) || $a === $b;
    }

    /** Члены класса и предков, названные в теле (методы, константы, статические свойства), становятся публичными. */
    private function publicize(Node\Stmt\ClassMethod $method, Node\Stmt\Class_ $declaring): void
    {
        $names = $this->memberNames($method);
        for ($class = $declaring; $class instanceof Node\Stmt\Class_;) {
            foreach ($class->stmts as $stmt) {
                if (
                    ($stmt instanceof Node\Stmt\Property && $stmt->isStatic() || $stmt instanceof Node\Stmt\ClassConst || $stmt instanceof Node\Stmt\ClassMethod)
                    && array_any($this->declaredNames($stmt), static fn(string $n): bool => isset($names[$n]))
                    && ($stmt->flags & (\PhpParser\Modifiers::PRIVATE | \PhpParser\Modifiers::PROTECTED)) !== 0
                ) {
                    $stmt->flags = $stmt->flags & ~(\PhpParser\Modifiers::PRIVATE | \PhpParser\Modifiers::PROTECTED) | \PhpParser\Modifiers::PUBLIC;
                    $this->publicized = true;
                }
            }

            $class = $class->extends instanceof Node\Name ? $this->classes[strtolower(ltrim($class->extends->toString(), '\\'))] ?? null : null;
        }
    }

    /** Ребёнок вычисляется безусловно, и всё, что вычисляется в родителе раньше него, — чисто. */
    private function evaluatedFirst(Node $parent, Node $child): bool
    {
        return match (true) {
            $parent instanceof Node\Arg => !$parent->byRef && !$parent->unpack,
            $parent instanceof Node\Expr\FuncCall => !$parent->name instanceof Node\Expr && $this->previousPure($parent->args, $child),
            $parent instanceof Node\Expr\New_ => !$parent->class instanceof Node\Expr && !$parent->class instanceof Node\Stmt\Class_
                && $this->previousPure($parent->args, $child),
            $parent instanceof Node\Expr\MethodCall, $parent instanceof Node\Expr\StaticCall => $child !== ($parent->name ?? null)
                && ($parent instanceof Node\Expr\StaticCall ? $parent->class instanceof Node\Name : ($parent->var === $child || $this->spillable($parent->var)))
                && $this->previousPure($parent->args, $child),
            $parent instanceof Node\Expr\Assign => $parent->expr === $child && $this->isAssignable($parent->var),
            $parent instanceof Node\Expr\BinaryOp\BooleanAnd, $parent instanceof Node\Expr\BinaryOp\BooleanOr,
            $parent instanceof Node\Expr\BinaryOp\LogicalAnd, $parent instanceof Node\Expr\BinaryOp\LogicalOr,
            $parent instanceof Node\Expr\BinaryOp\Coalesce => $parent->left === $child,
            $parent instanceof Node\Expr\BinaryOp => $parent->left === $child || $this->spillable($parent->left),
            $parent instanceof Node\Expr\Ternary => $parent->cond === $child,
            $parent instanceof Node\Expr\Match_ => $parent->cond === $child,
            $parent instanceof Node\Expr\Cast, $parent instanceof Node\Expr\BooleanNot, $parent instanceof Node\Expr\UnaryMinus,
            $parent instanceof Node\Expr\UnaryPlus, $parent instanceof Node\Expr\BitwiseNot, $parent instanceof Node\Expr\Throw_,
            $parent instanceof Node\Expr\Clone_, $parent instanceof Node\Expr\Print_, $parent instanceof Node\Expr\Empty_ => true,
            $parent instanceof Node\Expr\Instanceof_ => $parent->expr === $child,
            $parent instanceof Node\Expr\PropertyFetch => $parent->var === $child,
            $parent instanceof Node\Expr\ArrayDimFetch => $parent->var === $child || $this->isImmune($parent->var),
            $parent instanceof Node\ArrayItem => $parent->value === $child && (!$parent->key instanceof Node\Expr || $this->spillable($parent->key)) && !$parent->byRef,
            $parent instanceof Node\Expr\Array_ => $this->previousPure($parent->items, $child),
            $parent instanceof Node\Scalar\InterpolatedString => $this->previousPure($parent->parts, $child),
            default => false,
        };
    }

    /**
     * Всё, что в списке стоит раньше ребёнка, не зависит от побочных эффектов тела.
     *
     * @param array<Node|null> $items
     */
    private function previousPure(array $items, Node $child): bool
    {
        foreach ($items as $item) {
            if ($item === $child) {
                return true;
            }

            $value = $item instanceof Node\Arg ? $item->value : $item;
            if ($item instanceof Node\ArrayItem) {
                if ($item->byRef || $item->unpack || $item->key instanceof Node\Expr && !$this->spillable($item->key) || !$this->spillable($item->value)) {
                    return false;
                }

                continue;
            }

            if ($value instanceof Node\InterpolatedStringPart) {
                continue;
            }

            // Аргумент по ссылке (или распаковка) не выгрузить во временную переменную
            if ($item instanceof Node\Arg && ($item->byRef || $item->unpack || !$this->isImmune($item->value) && $this->referenceable($item->value) && $this->mayBeByRef($item, true))) {
                return false;
            }

            if (!$value instanceof Node\Expr || !$this->spillable($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Выражение, вычисляемое раньше встраиваемого вызова: неизменяемое — остаётся на месте,
     * иначе выгружается во временную переменную перед телом (в исходном порядке).
     */
    private function spillable(Node\Expr $expr): bool
    {
        if ($this->isImmune($expr)) {
            return true;
        }

        if ($this->findNode(Node\Expr\Yield_::class, [$expr]) !== [] || $this->findNode(Node\Expr\YieldFrom::class, [$expr]) !== []) {
            return false;
        }

        $this->spill[] = $expr;

        return true;
    }

    /**
     * Выгруженные выражения → `$tmp = выражение;` (в порядке вычисления), на их месте —
     * временная переменная.
     *
     * @return list<Node\Stmt>
     */
    private function spilled(Node $call): array
    {
        $scope  = $this->enclosingFunction($call);
        $key    = $scope instanceof Node\FunctionLike ? spl_object_id($scope) : 0;
        $this->takenNames($scope);
        $result = [];
        foreach ($this->spill as $expr) {
            $index = 1;
            while (isset($this->taken[$key]['spill' . $index])) {
                $index++;
            }

            $name                     = 'spill' . $index;
            $this->taken[$key][$name] = true;
            $parent                   = $expr->getAttribute('parent');
            $temp                     = new Node\Expr\Variable($name);
            if (!$parent instanceof Node || !$this->replaceChild($parent, $expr, $temp)) {
                continue;
            }

            $result[] = new Node\Stmt\Expression(new Node\Expr\Assign(new Node\Expr\Variable($name), $expr));
        }

        $this->spill = [];

        return $result;
    }

    private function replaceChild(Node $parent, Node $old, Node $new): bool
    {
        foreach ($parent->getSubNodeNames() as $name) {
            if ($parent->$name === $old) {
                $parent->$name = $new;
                $new->setAttribute('parent', $parent);

                return true;
            }

            if (is_array($parent->$name)) {
                foreach ($parent->$name as $index => $child) {
                    if ($child === $old) {
                        $parent->$name[$index] = $new;
                        $new->setAttribute('parent', $parent);

                        return true;
                    }
                }
            }
        }

        return false;
    }

    /** Выражение, на которое можно взять ссылку (переменная, свойство, элемент). */
    private function referenceable(Node\Expr $expr): bool
    {
        return $expr instanceof Node\Expr\Variable || $expr instanceof Node\Expr\PropertyFetch || $expr instanceof Node\Expr\StaticPropertyFetch
            || $expr instanceof Node\Expr\ArrayDimFetch;
    }

    /**
     * Значение не меняется от выполнения тела функции: литерал, константа, локальная
     * переменная (тело не видит её: параметры не по ссылке, без global).
     */
    private function isImmune(Node\Expr $expr): bool
    {
        return match (true) {
            $expr instanceof Node\Scalar && !$expr instanceof Node\Scalar\InterpolatedString => true,
            $expr instanceof Node\Scalar\InterpolatedString => array_all(
                $expr->parts,
                fn(Node $p): bool => $p instanceof Node\InterpolatedStringPart || $p instanceof Node\Expr && $this->isImmune($p),
            ),
            $expr instanceof Node\Expr\ConstFetch, $expr instanceof Node\Expr\ClassConstFetch && $expr->class instanceof Node\Name => true,
            $expr instanceof Node\Expr\Variable => is_string($expr->name) && !in_array($expr->name, ['this', 'GLOBALS', '_SESSION', '_GET', '_POST', '_COOKIE', '_SERVER', '_ENV', '_REQUEST', '_FILES'], true)
                || $expr->name === 'this',
            $expr instanceof Node\Expr\Array_ => array_all(
                $expr->items,
                fn(Node\ArrayItem $i): bool => !$i->byRef && !$i->unpack && $this->isImmune($i->value)
                    && (!$i->key instanceof Node\Expr || $this->isImmune($i->key)),
            ),
            default => false,
        };
    }

    /** Цель присваивания вычисляется до правой части без побочных эффектов. */
    private function isAssignable(Node\Expr $var): bool
    {
        return match (true) {
            $var instanceof Node\Expr\Variable => is_string($var->name),
            $var instanceof Node\Expr\PropertyFetch => $var->name instanceof Node\Identifier && $var->var instanceof Node\Expr\Variable,
            $var instanceof Node\Expr\StaticPropertyFetch => $var->class instanceof Node\Name,
            $var instanceof Node\Expr\ArrayDimFetch => $this->isAssignable($var->var) && (!$var->dim instanceof Node\Expr || $this->isImmune($var->dim)),
            default => false,
        };
    }

    /**
     * Пролог (присваивания параметров + тело) и выражение результата.
     *
     * @return array{list<Node\Stmt>, Node\Expr}|null
     */
    private function inline(Node\Stmt\Function_|Node\Stmt\ClassMethod $function, Node\Expr\FuncCall|Node\Expr\MethodCall|Node\Expr\StaticCall $call, ?array $method = null): ?array
    {
        if ($call->isPartialFunctionApplication()) {
            return null; // f(...), f(?, 1) — создание замыкания, не вызов
        }

        $args = $call->getArgs();
        if (count($args) > count($function->params)) {
            return null;
        }

        foreach ($args as $arg) {
            if ($arg->unpack || $arg->byRef || $arg->name instanceof Node\Identifier) {
                return null;
            }
        }

        $scope  = $this->enclosingFunction($call);
        $key    = $scope instanceof \PhpParser\Node\FunctionLike ? spl_object_id($scope) : 0;
        $this->takenNames($scope);
        $rename = [];
        $fresh  = function (string $name) use ($key): string {
            $index = 1;
            while (isset($this->taken[$key][$name . $index])) {
                $index++;
            }

            $this->taken[$key][$name . $index] = true;

            return $name . $index;
        };

        foreach ($this->findNode(Node\Expr\Variable::class, $function->stmts ?? []) as $variable) {
            if (is_string($variable->name) && $variable->name !== 'this' && !isset($rename[$variable->name]) && !$this->superGlobal($variable->name)) {
                $rename[$variable->name] = $fresh($variable->name);
            }
        }

        $prelude  = [];
        $literals = [];
        // Получатель вычисляется до аргументов; $this тела — он
        if ($call instanceof Node\Expr\MethodCall) {
            $receiver = $call->var;
            if ($receiver instanceof Node\Expr\Variable && is_string($receiver->name) && !$this->superGlobal($receiver->name)) {
                $rename['this'] = $receiver->name;
            } else {
                $base           = $receiver instanceof Node\Expr\PropertyFetch && $receiver->name instanceof Node\Identifier ? $receiver->name->toString() : 'object';
                $rename['this'] = $fresh($base);
                $prelude[]      = new Node\Stmt\Expression(new Node\Expr\Assign(new Node\Expr\Variable($rename['this']), $receiver));
            }
        }

        foreach ($function->params as $index => $param) {
            assert($param->var instanceof Node\Expr\Variable && is_string($param->var->name));
            $name                 = $param->var->name;
            $rename[$name]      ??= $fresh($name);
            $value                = isset($args[$index]) ? $args[$index]->value : $param->default;
            if (!$value instanceof Node\Expr) {
                return null; // обязательный аргумент не передан — ArgumentCountError
            }

            $target = new Node\Expr\Variable($rename[$name]);
            $cast   = $this->castFor($param->type);
            if ($cast === false) {
                return null;
            }

            // Тип аргумента известен (литерал, null для nullable) — приведение не нужно
            if (
                $cast !== null && ($this->knownType($value) === $cast
                || $value instanceof Node\Expr\ConstFetch && $value->name->toLowerString() === 'null' && $this->nullable($param->type))
            ) {
                $cast = null;
            }

            // Параметр не меняется в теле: аргумент-переменная или литерал подставляется прямо
            if ($cast === null && !$this->written($function, $name)) {
                if ($value instanceof Node\Expr\Variable && is_string($value->name) && !$this->superGlobal($value->name)) {
                    $rename[$name] = $value->name;
                    continue;
                }

                if ($this->isLiteral($value) && !$this->capturedByClosure($function, $name)) {
                    $literals[$rename[$name]] = $value;
                    continue;
                }
            }

            $prelude[] = new Node\Stmt\Expression(new Node\Expr\Assign($target, $value));
            if ($cast !== null) {
                $prelude[] = $this->castStatement($rename[$name], $cast, $this->nullable($param->type));
            }
        }

        $resultName = $fresh(strtolower(preg_replace('/^.*_/', '', $function->name->toString()) ?: 'result') . 'Result');
        $body       = $this->withLiterals($this->renamed($this->deepCloneAll($function->stmts ?? []), $rename), $literals);
        if ($method !== null) {
            [$declaring, $concrete] = $method;
            $body = $this->withClassNames($body, $declaring, $concrete);
            assert($function instanceof Node\Stmt\ClassMethod);
            $this->publicize($function, $declaring);
        }

        $returnCast = $this->castFor($function->returnType);
        if ($returnCast === false) {
            return null;
        }

        $converted = $this->convertReturns($body, $resultName, 0);
        if ($converted === null) {
            return null;
        }

        [$statements, $early] = $converted;
        $last = end($function->stmts);

        // Без ранних return: выражение последнего return — прямо на место вызова
        if (!$early) {
            $final = end($statements);
            $tail  = $last instanceof Node\Stmt\Return_ && $last->expr instanceof Node\Expr && $final instanceof Node\Stmt\Expression
                && $final->expr instanceof Node\Expr\Assign ? $final->expr->expr : null;
            if ($tail instanceof Node\Expr && ($returnCast === null || $this->knownType($tail) === $returnCast)) {
                array_pop($statements);
                $this->counter++;

                return [[...$prelude, ...$statements], $tail];
            }

            if (!$last instanceof Node\Stmt\Return_ && !$this->hasReturn($function->stmts)) {
                $this->counter++;

                return [[...$prelude, ...$statements], new Node\Expr\ConstFetch(new Node\Name('null'))];
            }
        }

        // Результат может не присвоиться (ветка без return) — сначала null
        if ($early || !$last instanceof Node\Stmt\Return_ || !$last->expr instanceof Node\Expr) {
            $prelude[] = new Node\Stmt\Expression(new Node\Expr\Assign(new Node\Expr\Variable($resultName), new Node\Expr\ConstFetch(new Node\Name('null'))));
        }

        if ($early) {
            $prelude[] = new Node\Stmt\Do_(new Node\Expr\ConstFetch(new Node\Name('false')), $statements);
        } else {
            array_push($prelude, ...$statements);
        }

        if ($returnCast !== null) {
            $prelude[] = $this->castStatement($resultName, $returnCast, $this->nullable($function->returnType));
        }

        $this->counter++;

        return [$prelude, new Node\Expr\Variable($resultName)];
    }

    /**
     * return → `$result = …;`; не последняя инструкция тела — плюс `break N` из обёртки
     * do-while (N — вложенные циклы и switch вокруг return внутри тела + 1).
     *
     * @param  list<Node\Stmt> $stmts
     * @return array{list<Node\Stmt>, bool}|null инструкции и нужна ли обёртка
     */
    private function convertReturns(array $stmts, string $result, int $depth, bool $top = true): ?array
    {
        $early = false;
        $count = count($stmts);
        $out   = [];
        foreach (array_values($stmts) as $index => $stmt) {
            if ($stmt instanceof Node\Stmt\Return_) {
                if ($stmt->expr instanceof Node\Expr) {
                    $out[] = new Node\Stmt\Expression(new Node\Expr\Assign(new Node\Expr\Variable($result), $stmt->expr));
                }

                if (!$top || $index !== $count - 1) {
                    $out[] = new Node\Stmt\Break_(new Node\Scalar\Int_($depth + 1));
                    $early = true;
                }

                continue;
            }

            $nested = $this->convertNested($stmt, $result, $depth);
            if ($nested === null) {
                return null;
            }

            $early = $early || $nested;
            $out[] = $stmt;
        }

        return [$out, $early];
    }

    /** Return во вложенных инструкциях (if, циклы, switch, try); true — был ранний return. */
    private function convertNested(Node\Stmt $stmt, string $result, int $depth): ?bool
    {
        if ($stmt instanceof Node\Stmt\Finally_ && $this->hasReturn($stmt->stmts)) {
            return null; // return в finally меняет исход исключения
        }

        $loop  = $stmt instanceof Node\Stmt\For_ || $stmt instanceof Node\Stmt\Foreach_ || $stmt instanceof Node\Stmt\While_
            || $stmt instanceof Node\Stmt\Do_ || $stmt instanceof Node\Stmt\Switch_;
        $inner = $loop ? $depth + 1 : $depth;
        $early = false;
        foreach ($stmt->getSubNodeNames() as $name) {
            $value = $stmt->$name;
            if (!is_array($value)) {
                $value = [$value];
                $single = true;
            } else {
                $single = false;
            }

            // Список инструкций (тело if/цикла/case/catch)
            if (
                !$single && $value !== [] && array_all($value, static fn(mixed $v): bool => $v instanceof Node\Stmt)
                && !array_any($value, static fn(mixed $v): bool => $v instanceof Node\Stmt\Else_ || $v instanceof Node\Stmt\ElseIf_
                    || $v instanceof Node\Stmt\Case_ || $v instanceof Node\Stmt\Catch_)
            ) {
                /** @var list<Node\Stmt> $list */
                $list      = array_values($value);
                $converted = $this->convertReturns($list, $result, $inner, false);
                if ($converted === null) {
                    return null;
                }

                $stmt->$name = $converted[0];
                $early       = $early || $converted[1];
                continue;
            }

            // Ветки else/elseif, case, catch, finally — их тела на той же глубине
            foreach ($value as $child) {
                if (
                    $child instanceof Node\Stmt\Else_ || $child instanceof Node\Stmt\ElseIf_ || $child instanceof Node\Stmt\Case_
                    || $child instanceof Node\Stmt\Catch_ || $child instanceof Node\Stmt\Finally_
                ) {
                    $nested = $this->convertNested($child, $result, $inner);
                    if ($nested === null) {
                        return null;
                    }

                    $early = $early || $nested;
                }
            }
        }

        return $early;
    }

    /** Параметр изменяется в теле (присваивание, ++, unset, foreach, ссылка, передача в вызов). */
    private function written(Node\Stmt\Function_|Node\Stmt\ClassMethod $function, string $name): bool
    {
        foreach ($this->findNode(Node::class, $function->stmts ?? []) as $node) {
            $targets = match (true) {
                $node instanceof Node\Expr\Assign, $node instanceof Node\Expr\AssignOp, $node instanceof Node\Expr\AssignRef,
                $node instanceof Node\Expr\PreInc, $node instanceof Node\Expr\PreDec, $node instanceof Node\Expr\PostInc,
                $node instanceof Node\Expr\PostDec => [$node->var],
                $node instanceof Node\Stmt\Unset_ => $node->vars,
                $node instanceof Node\Stmt\Foreach_ => [$node->keyVar, $node->valueVar, $node->byRef ? $node->expr : null],
                $node instanceof Node\Expr\ClosureUse => $node->byRef ? [$node->var] : [],
                $node instanceof Node\Arg => $this->mayBeByRef($node) ? [$node->value] : [],
                default => [],
            };
            foreach ($targets as $target) {
                while ($target instanceof Node\Expr\ArrayDimFetch || $target instanceof Node\Expr\PropertyFetch) {
                    $target = $target->var;
                }

                if ($target instanceof Node\Expr\Variable && $target->name === $name || $target instanceof Node\Expr\List_ || $target instanceof Node\Expr\Array_) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Передача по ссылке: у встроенной функции — по рефлексии; иначе — возможно. */
    private function mayBeByRef(Node\Arg $arg, bool $anyValue = false): bool
    {
        $call = $arg->getAttribute('parent');
        if (!$anyValue && !$arg->value instanceof Node\Expr\Variable) {
            return false;
        }

        $position = $call instanceof Node\Expr\CallLike ? array_search($arg, $call->args, true) : false;

        // Метод программы: по ссылке, если так объявлен хоть один одноимённый метод
        if (($call instanceof Node\Expr\MethodCall || $call instanceof Node\Expr\StaticCall || $call instanceof Node\Expr\NullsafeMethodCall) && $call->name instanceof Node\Identifier) {
            if ($this->methodsByName === null) {
                $this->methodsByName = [];
                foreach ($this->findNode(Node\Stmt\ClassMethod::class) as $declared) {
                    $this->methodsByName[$declared->name->toLowerString()][] = $declared;
                }
            }

            $methods = $this->methodsByName[$call->name->toLowerString()] ?? [];

            return $methods === [] || !is_int($position) || array_any($methods, static function (Node\Stmt\ClassMethod $m) use ($position): bool {
                $param = $m->params[$position] ?? end($m->params);

                return $param === false || $param->byRef;
            });
        }

        if ($call instanceof Node\Expr\FuncCall && $call->name instanceof Node\Name && isset($this->functionsByName[strtolower(ltrim($call->name->toString(), '\\'))])) {
            $declared = $this->functionsByName[strtolower(ltrim($call->name->toString(), '\\'))];
            $param    = is_int($position) ? $declared->params[$position] ?? end($declared->params) : false;

            return $param === false || $param->byRef;
        }

        if (!$call instanceof Node\Expr\FuncCall || !$call->name instanceof Node\Name || !function_exists($call->name->toString())) {
            return true;
        }

        $parameters = new \ReflectionFunction($call->name->toString())->getParameters();
        $parameter  = $parameters[is_int($position) ? $position : 0] ?? end($parameters);

        return $parameter !== false && $parameter->isPassedByReference();
    }

    private function capturedByClosure(Node\Stmt\Function_|Node\Stmt\ClassMethod $function, string $name): bool
    {
        return array_any($this->findNode(Node\Expr\ClosureUse::class, $function->stmts), static fn(Node\Expr\ClosureUse $u): bool => $u->var->name === $name);
    }

    private function isLiteral(Node\Expr $expr): bool
    {
        return $expr instanceof Node\Scalar && !$expr instanceof Node\Scalar\InterpolatedString
            || $expr instanceof Node\Expr\ConstFetch
            || $expr instanceof Node\Expr\ClassConstFetch && $expr->class instanceof Node\Name
            || $expr instanceof Node\Expr\UnaryMinus && $this->isLiteral($expr->expr);
    }

    /**
     * Переменные-заглушки параметров заменяются литералами аргументов.
     *
     * @param  list<Node\Stmt>          $stmts
     * @param  array<string, Node\Expr>  $literals
     * @return list<Node\Stmt>
     */
    private function withLiterals(array $stmts, array $literals): array
    {
        if ($literals === []) {
            return $stmts;
        }

        $result = new NodeTraverser(new class ($literals, $this) extends NodeVisitorAbstract {
            /** @param array<string, Node\Expr> $literals */
            public function __construct(private readonly array $literals, private readonly CallSiteInlinerVisitor $owner)
            {
            }

            public function leaveNode(Node $node): ?Node
            {
                return $node instanceof Node\Expr\Variable && is_string($node->name) && isset($this->literals[$node->name])
                    ? $this->owner->copy($this->literals[$node->name])
                    : null;
            }
        })->traverse($stmts);

        return array_values(array_filter($result, static fn(Node $n): bool => $n instanceof Node\Stmt));
    }

    /**
     * self → класс объявления, static → конкретный класс получателя.
     *
     * @param  list<Node\Stmt> $stmts
     * @return list<Node\Stmt>
     */
    private function withClassNames(array $stmts, Node\Stmt\Class_ $declaring, ?Node\Stmt\Class_ $concrete): array
    {
        $names = ['self' => (string) $declaring->name];
        if ($concrete instanceof Node\Stmt\Class_) {
            $names['static'] = (string) $concrete->name;
        }

        $result = new NodeTraverser(new class ($names) extends NodeVisitorAbstract {
            /** @param array<string, string> $names */
            public function __construct(private readonly array $names)
            {
            }

            public function leaveNode(Node $node): ?Node
            {
                return $node instanceof Node\Name && isset($this->names[$node->toLowerString()])
                    ? new Node\Name\FullyQualified($this->names[$node->toLowerString()], $node->getAttributes())
                    : null;
            }
        })->traverse($stmts);

        return array_values(array_filter($result, static fn(Node $n): bool => $n instanceof Node\Stmt));
    }

    public function copy(Node $node): Node
    {
        return $this->deepClone($node);
    }

    /** Скалярный тип выражения по его виду ('string', 'int', 'bool', 'float'); null — неизвестно. */
    private function knownType(Node\Expr $expr): ?string
    {
        return match (true) {
            $expr instanceof Node\Scalar\String_, $expr instanceof Node\Scalar\InterpolatedString, $expr instanceof Node\Expr\BinaryOp\Concat,
            $expr instanceof Node\Expr\Cast\String_ => 'string',
            $expr instanceof Node\Scalar\Int_, $expr instanceof Node\Expr\Cast\Int_ => 'int',
            $expr instanceof Node\Scalar\Float_, $expr instanceof Node\Expr\Cast\Double => 'float',
            $expr instanceof Node\Expr\Cast\Bool_, $expr instanceof Node\Expr\BooleanNot, $expr instanceof Node\Expr\Instanceof_,
            $expr instanceof Node\Expr\Isset_, $expr instanceof Node\Expr\Empty_, $expr instanceof Node\Expr\BinaryOp\Identical,
            $expr instanceof Node\Expr\BinaryOp\NotIdentical, $expr instanceof Node\Expr\BinaryOp\Equal, $expr instanceof Node\Expr\BinaryOp\NotEqual,
            $expr instanceof Node\Expr\BinaryOp\Greater, $expr instanceof Node\Expr\BinaryOp\GreaterOrEqual, $expr instanceof Node\Expr\BinaryOp\Smaller,
            $expr instanceof Node\Expr\BinaryOp\SmallerOrEqual, $expr instanceof Node\Expr\BinaryOp\BooleanAnd, $expr instanceof Node\Expr\BinaryOp\BooleanOr,
            $expr instanceof Node\Expr\BinaryOp\LogicalAnd, $expr instanceof Node\Expr\BinaryOp\LogicalOr, $expr instanceof Node\Expr\BinaryOp\LogicalXor => 'bool',
            $expr instanceof Node\Expr\ConstFetch => in_array($expr->name->toLowerString(), ['true', 'false'], true) ? 'bool' : null,
            $expr instanceof Node\Expr\Ternary => $expr->if instanceof Node\Expr && ($type = $this->knownType($expr->if)) === $this->knownType($expr->else) ? $type : null,
            $expr instanceof Node\Expr\FuncCall => $this->internalResultType($expr),
            $expr instanceof Node\Expr\Variable => $this->parameterType($expr),
            default => null,
        };
    }

    /** Встроенная функция с объявленным скалярным типом результата (strtoupper → string). */
    private function internalResultType(Node\Expr\FuncCall $call): ?string
    {
        if (!$call->name instanceof Node\Name || !function_exists($call->name->toString())) {
            return null;
        }

        $function = new \ReflectionFunction($call->name->toString());
        $type     = $function->isInternal() ? $function->getReturnType() : null;

        return $type instanceof \ReflectionNamedType && !$type->allowsNull() && in_array($type->getName(), ['int', 'float', 'string', 'bool'], true)
            ? $type->getName()
            : null;
    }

    /** Параметр функции со скалярным типом, который в ней не переприсваивается. */
    private function parameterType(Node\Expr\Variable $variable): ?string
    {
        $function = $this->enclosingFunction($variable);
        if (!$function instanceof Node\Stmt\Function_ && !$function instanceof Node\Stmt\ClassMethod || !is_string($variable->name)) {
            return null;
        }

        foreach ($function->params as $param) {
            if ($param->var instanceof Node\Expr\Variable && $param->var->name === $variable->name) {
                $type = $param->type instanceof Node\Identifier ? $param->type->toLowerString() : null;

                return in_array($type, ['int', 'float', 'string', 'bool'], true) && !$param->byRef && !$this->written($function, $variable->name) ? $type : null;
            }
        }

        return null;
    }

    /** @param array<Node> $stmts */
    private function hasReturn(array $stmts): bool
    {
        return $this->findNode(Node\Stmt\Return_::class, $stmts) !== [];
    }

    /**
     * Приведение скалярного типа: 'int' и т.п.; null — не нужно (класс, array, без
     * типа); false — тип, приведение которого так не выразить (объединение скаляров).
     */
    private function castFor(?Node $type): string|false|null
    {
        $bare = $type instanceof Node\NullableType ? $type->type : $type;
        if ($bare instanceof Node\UnionType) {
            $names = array_map(static fn(Node $t): string => $t instanceof Node\Identifier ? $t->toLowerString() : 'class', $bare->types);
            $scalars = array_intersect($names, ['int', 'float', 'string', 'bool', 'false', 'true']);
            if ($scalars === []) {
                return null;
            }

            return count(array_diff($names, ['null'])) === 1 ? reset($scalars) : false;
        }

        if (!$bare instanceof Node\Identifier) {
            return null;
        }

        return match ($bare->toLowerString()) {
            'int', 'float', 'string', 'bool' => $bare->toLowerString(),
            'void', 'never', 'mixed', 'array', 'object', 'iterable', 'callable', 'null', 'self', 'static' => null,
            'false', 'true' => false,
            default => null,
        };
    }

    private function nullable(?Node $type): bool
    {
        return $type instanceof Node\NullableType
            || $type instanceof Node\UnionType && array_any($type->types, static fn(Node $t): bool => $t instanceof Node\Identifier && $t->toLowerString() === 'null');
    }

    /** `$x = (int) $x;` или для nullable — `if ($x !== null) { $x = (int) $x; }` */
    private function castStatement(string $variable, string $type, bool $nullable): Node\Stmt
    {
        $cast = match ($type) {
            'int' => new Node\Expr\Cast\Int_(new Node\Expr\Variable($variable)),
            'float' => new Node\Expr\Cast\Double(new Node\Expr\Variable($variable)),
            'bool' => new Node\Expr\Cast\Bool_(new Node\Expr\Variable($variable)),
            default => new Node\Expr\Cast\String_(new Node\Expr\Variable($variable)),
        };
        $assign = new Node\Stmt\Expression(new Node\Expr\Assign(new Node\Expr\Variable($variable), $cast));
        if (!$nullable) {
            return $assign;
        }

        return new Node\Stmt\If_(
            new Node\Expr\BinaryOp\NotIdentical(new Node\Expr\Variable($variable), new Node\Expr\ConstFetch(new Node\Name('null'))),
            ['stmts' => [$assign]],
        );
    }

    /**
     * Переименование переменных тела; во вложенном замыкании — только захваченные (use),
     * в стрелочной функции — все, кроме её параметров.
     *
     * @param  list<Node\Stmt>        $stmts
     * @param  array<string, string>  $rename
     * @return list<Node\Stmt>
     */
    private function renamed(array $stmts, array $rename): array
    {
        $visitor = new class ($rename) extends NodeVisitorAbstract {
            /** @var list<array<string, string>> */
            private array $stack;

            /** @param array<string, string> $rename */
            public function __construct(array $rename)
            {
                $this->stack = [$rename];
            }

            public function enterNode(Node $node): null
            {
                $current = end($this->stack) ?: [];
                if ($node instanceof Node\Expr\Closure) {
                    $inner = [];
                    foreach ($node->uses as $use) {
                        if (is_string($use->var->name) && isset($current[$use->var->name])) {
                            $inner[$use->var->name] = $current[$use->var->name];
                        }
                    }

                    $this->stack[] = $inner;
                } elseif ($node instanceof Node\Expr\ArrowFunction) {
                    $inner = $current;
                    foreach ($node->params as $param) {
                        if ($param->var instanceof Node\Expr\Variable && is_string($param->var->name)) {
                            unset($inner[$param->var->name]);
                        }
                    }

                    $this->stack[] = $inner;
                }

                return null;
            }

            public function leaveNode(Node $node): ?Node
            {
                if ($node instanceof Node\Expr\Closure) {
                    array_pop($this->stack);
                    // use ($x) — имя из внешней области
                    $outer = end($this->stack);
                    foreach ($node->uses as $use) {
                        if (is_string($use->var->name) && isset($outer[$use->var->name])) {
                            $use->var->name = $outer[$use->var->name];
                        }
                    }

                    return null;
                }

                if ($node instanceof Node\Expr\ArrowFunction) {
                    array_pop($this->stack);

                    return null;
                }

                if ($node instanceof Node\Expr\Variable && is_string($node->name) && !$node->getAttribute('closureUse')) {
                    $current = end($this->stack);
                    if (isset($current[$node->name])) {
                        $node->name = $current[$node->name];
                    }
                }

                return null;
            }
        };

        // Переменные в use(...) переименовываются по внешней области (leaveNode замыкания)
        foreach ($this->findNode(Node\Expr\ClosureUse::class, $stmts) as $use) {
            $use->var->setAttribute('closureUse', true);
        }

        $result = new NodeTraverser($visitor)->traverse($stmts);
        foreach ($this->findNode(Node\Expr\ClosureUse::class, $result) as $use) {
            $use->var->setAttribute('closureUse', null);
        }

        return array_values(array_filter($result, static fn(Node $n): bool => $n instanceof Node\Stmt));
    }

    /**
     * @param  list<Node\Stmt> $stmts
     * @return list<Node\Stmt>
     */
    private function deepCloneAll(array $stmts): array
    {
        $result = [];
        foreach ($stmts as $stmt) {
            $copy = $this->deepClone($stmt);
            assert($copy instanceof Node\Stmt);
            $result[] = $copy;
        }

        return $result;
    }

    /** @return array<string, true> имена переменных области видимости вызова */
    private function takenNames(?Node\FunctionLike $scope): array
    {
        $key = $scope instanceof \PhpParser\Node\FunctionLike ? spl_object_id($scope) : 0;
        if (!isset($this->taken[$key])) {
            $names = [];
            $nodes = $scope instanceof \PhpParser\Node\FunctionLike ? array_merge($scope->getParams(), $scope->getStmts() ?? []) : $this->ast;
            foreach ($this->findNode(Node\Expr\Variable::class, $nodes) as $variable) {
                if (is_string($variable->name)) {
                    $names[$variable->name] = true;
                }
            }

            if ($scope instanceof Node\Expr\Closure) {
                foreach ($scope->uses as $use) {
                    if (is_string($use->var->name)) {
                        $names[$use->var->name] = true;
                    }
                }
            }

            $this->taken[$key] = $names;
        }

        return $this->taken[$key];
    }

    private function superGlobal(string $name): bool
    {
        return in_array($name, ['GLOBALS', '_SERVER', '_GET', '_POST', '_FILES', '_COOKIE', '_SESSION', '_REQUEST', '_ENV'], true);
    }

    private function enclosingFunction(Node $node): ?Node\FunctionLike
    {
        for ($current = $node->getAttribute('parent'); $current instanceof Node; $current = $current->getAttribute('parent')) {
            if ($current instanceof Node\FunctionLike) {
                return $current;
            }
        }

        return null;
    }
}
