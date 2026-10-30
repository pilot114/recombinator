<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;
use PhpParser\NodeFinder;
use Recombinator\Core\Config;
use Recombinator\Support\ExternalCode;
use Recombinator\Support\StringValues;
use Recombinator\Support\TypeInference;

/**
 * Удаляет методы, недостижимые из живого кода.
 *
 * Живой код — верхний уровень, функции, всё в классах, кроме тел методов, и тела
 * живых методов (транзитивно). Вызов в живом коде оживляет методы по типу получателя
 * (анализ по иерархии классов):
 * - `$this->m()`, `static::m()` — метод класса (или предка) и переопределения в наследниках;
 * - `self::m()`, `parent::m()`, `X::m()` — метод, найденный вверх по предкам;
 * - `$this->prop->m()`, `$param->m()` — по объявленному (исходному) типу свойства или
 *   непереприсваиваемого параметра: метод во всех классах программы этого типа;
 * - `(new X)->m()` — метод класса X.
 * Если тип получателя неизвестен — живы все методы с этим именем, как и при строке
 * `'m'` (`[$obj, 'm']`, `'C::m'`, нотация Slim `'C:m'`, `method_exists($x, 'm')`).
 * Строка, собранная из частей с литеральным началом (`'get' . $x`, `"get{$x}"`),
 * оживляет все методы с этим префиксом.
 *
 * Абстрактный метод или метод интерфейса остаётся, пока жива хоть одна его реализация
 * или его имя; тогда остаются и все реализации — иначе класс стал бы абстрактным.
 *
 * Остаются всегда: магические методы (`__construct`, `__invoke`, `__call`, …),
 * методы с атрибутами (их находят рефлексией: колбэки жизненного цикла Doctrine),
 * методы, которые объявляет внешний предок (`getIterator`, `offsetGet`, …) или
 * предок, которого нет в программе, методы классов публичного контракта.
 *
 * Как и в RemoveUnusedClassVisitor, не видны имена, вычисленные иначе (из данных,
 * `ucfirst($x)`), и вызовы из невстраиваемых include — те же допущения, что и для классов.
 */
#[VisitorMeta('Удаление методов, имя которых не используется в живом коде')]
class RemoveUnusedMethodVisitor extends BaseVisitor
{
    /** @var \SplObjectStorage<Node\Stmt\ClassMethod, true> */
    private \SplObjectStorage $dead;

    private readonly Config $config;

    /** @var array<string, Node\Stmt\ClassLike> */
    private array $classes = [];

    private ?TypeInference $inference = null;

    /** @var array<string, list<Node\Stmt\Function_>>|null */
    private ?array $functions = null;

    /** @var array<string, list<Node\Expr\CallLike>>|null lowercase имя → вызовы */
    private ?array $callSites = null;

    /** @var array<int, bool> кэш mayNameMethod по spl_object_id выражения */
    private array $namesMethod = [];

    /** @var array<int, true> */
    private array $inProgress = [];

    private int $assumed = 0;

    /** @var array<string, list<Node\Stmt\ClassMethod>>|null */
    private ?array $allMethods = null;

    /** @var array<string, list<Node\Expr>>|null имя свойства → обращения */
    private ?array $propertyReads = null;

    /** @var array<string, list<Node\Stmt\ClassMethod>> */
    private array $methodsByName = [];

    /** @var array<int, array<string, true>> */
    private array $ancestry = [];

    /** @var \SplObjectStorage<Node\Stmt\ClassMethod, true> */
    private \SplObjectStorage $liveMethods;

    /** @var list<Node\Stmt\ClassMethod> живые методы, тела которых ещё не разобраны */
    private array $pending = [];

    /**
     * @param array<string, string> $fileMap файлы проекта (путь → код): файлы, чьи классы не
     *                                       вошли в программу, загрузятся автозагрузкой во время
     *                                       выполнения и могут вызвать методы программы (провайдеры
     *                                       Faker наследуют встроенный `Faker\Provider\Base`)
     */
    public function __construct(?Config $config = null, private readonly array $fileMap = [])
    {
        $this->config      = $config ?? new Config();
        $this->dead        = new \SplObjectStorage();
        $this->liveMethods = new \SplObjectStorage();
    }

    #[\Override]
    public function beforeTraverse(array $nodes): ?array
    {
        parent::beforeTraverse($nodes);
        $this->dead = new \SplObjectStorage();
        // Ждём встраивания подключаемых файлов; невстраиваемые (данные, прокси Doctrine)
        // не учитываются — то же соглашение, что у StaticMethodExtractVisitor
        if ($this->hasPendingIncludes()) {
            return null;
        }

        /** @var array<string, Node\Stmt\ClassLike> $classes */
        $classes = [];
        foreach ($this->findNode(Node\Stmt\ClassLike::class) as $class) {
            if ($class->name instanceof Node\Identifier) {
                $classes[$class->name->toLowerString()] = $class;
            }
        }

        // Кандидаты: обычные методы; остальные методы и всё прочее — корни
        /** @var array<string, list<Node\Stmt\ClassMethod>> $methods */
        $methods = [];
        $roots   = [];
        $this->classes   = $classes;
        $this->inference = new TypeInference($classes, $this->ast);
        $this->partition($nodes, $classes, $methods, $roots);

        $this->functions    = null;
        $this->callSites    = null;
        $this->namesMethod  = [];
        $this->propertyReads = null;
        $this->allMethods    = null;
        $this->ancestry     = [];
        $this->pending      = [];
        $this->liveMethods  = new \SplObjectStorage();
        $this->methodsByName = $methods;

        $live     = [];
        $prefixes = [];
        $queue    = [];
        $this->collectNames($roots, $live, $prefixes, $queue);
        $external = $this->fileMap === [] ? [] : ExternalCode::forProgram($this->fileMap, $classes, $this->ast)->names();
        foreach (array_keys($external) as $name) {
            if (!isset($live[$name])) {
                $live[$name] = true;
                $queue[]     = (string) $name;
            }
        }

        do {
            while ($queue !== [] || $this->pending !== []) {
                // Имя живо — живы все методы с этим именем
                while ($queue !== []) {
                    foreach ($methods[array_pop($queue)] ?? [] as $method) {
                        $this->markLive($method);
                    }
                }

                while ($this->pending !== []) {
                    $method = array_pop($this->pending);
                    $this->collectNames($method->stmts ?? [], $live, $prefixes, $queue);
                }
            }

            // Префиксы оживляют сразу все подходящие методы
            foreach (array_keys($methods) as $candidate) {
                if (!isset($live[$candidate]) && $this->matchesPrefix((string) $candidate, $prefixes)) {
                    $live[$candidate] = true;
                    $queue[]          = (string) $candidate;
                }
            }

            // Объявление абстрактного метода живо — живы и все его реализации
            foreach ($this->abstractImplementations() as $implementation) {
                $this->markLive($implementation);
            }
        } while ($queue !== [] || $this->pending !== []);

        foreach ($methods as $declarations) {
            foreach ($declarations as $method) {
                if (!isset($this->liveMethods[$method])) {
                    $this->dead[$method] = true;
                }
            }
        }

        return null;
    }

    private function markLive(Node\Stmt\ClassMethod $method): void
    {
        if (isset($this->liveMethods[$method])) {
            return;
        }

        $this->liveMethods[$method] = true;
        $this->pending[]            = $method;
    }

    /**
     * Реализации живых абстрактных методов и методов интерфейсов (объявление живо, если
     * жива его реализация; наоборот — реализации нужны, пока есть объявление).
     *
     * @return list<Node\Stmt\ClassMethod>
     */
    private function abstractImplementations(): array
    {
        $result = [];
        foreach ($this->methodsByName as $declarations) {
            foreach ($declarations as $declaration) {
                if ($declaration->stmts !== null) {
                    continue;
                }

                $owner           = $this->ownerOf($declaration);
                $implementations = [];
                foreach ($declarations as $candidate) {
                    $class = $this->ownerOf($candidate);
                    if (
                        $candidate->stmts !== null && $class instanceof Node\Stmt\ClassLike && $owner instanceof Node\Stmt\ClassLike
                        && $class->name instanceof Node\Identifier && $owner->name instanceof Node\Identifier
                        && isset($this->ancestorsOf($class)[$owner->name->toLowerString()])
                    ) {
                        $implementations[] = $candidate;
                    }
                }

                $alive = isset($this->liveMethods[$declaration]) || array_any($implementations, fn(Node\Stmt\ClassMethod $m): bool => isset($this->liveMethods[$m]));
                if ($alive) {
                    $this->liveMethods[$declaration] = true;
                    array_push($result, ...$implementations);
                }
            }
        }

        return array_values(array_filter($result, fn(Node\Stmt\ClassMethod $m): bool => !isset($this->liveMethods[$m])));
    }

    public function enterNode(Node $node): ?Node
    {
        if ($node instanceof Node\Stmt\ClassMethod && isset($this->dead[$node])) {
            $node->setAttribute('remove', true);
        }

        return null;
    }

    /**
     * Делит программу на кандидатов в удаление (по lowercase имени) и корни.
     *
     * @param array<Node>                                 $nodes
     * @param array<string, Node\Stmt\ClassLike>          $classes
     * @param array<string, list<Node\Stmt\ClassMethod>>  $methods
     * @param list<Node>                                  $roots
     */
    private function partition(array $nodes, array $classes, array &$methods, array &$roots): void
    {
        $finder  = new NodeFinder();
        $inside  = $finder->findInstanceOf($nodes, Node\Stmt\ClassLike::class);
        $plugins = $this->plugins($classes);
        foreach ($inside as $class) {
            $protected = $class->name instanceof Node\Identifier
                && ($this->config->isProtected($class->name->toString()) || isset($plugins[$class->name->toLowerString()]));
            foreach ($class->stmts as $stmt) {
                if (!$stmt instanceof Node\Stmt\ClassMethod) {
                    $roots[] = $stmt;
                    continue;
                }

                if ($protected || $this->pinned($stmt, $class, $classes)) {
                    $roots[] = $stmt;
                    continue;
                }

                $methods[$stmt->name->toLowerString()][] = $stmt;
            }
        }

        // Код вне классов; тела классов разобраны выше (вложенные в функции классы — тоже)
        foreach ($nodes as $node) {
            foreach ($node instanceof Node\Stmt\Namespace_ ? $node->stmts : [$node] as $stmt) {
                if (!$stmt instanceof Node\Stmt\ClassLike) {
                    $roots[] = $stmt;
                }
            }
        }
    }

    /**
     * Классы, создаваемые по имени, собранному во время выполнения (провайдеры Faker:
     * `new (sprintf('Faker\Provider\%s', $p))`), и их предки. Такие классы — плагины:
     * их методы вызывают по именам из данных (`'{{userName}}'` → `$provider->userName()`),
     * поэтому все методы живы — как у внешнего кода, которым они были до встраивания.
     *
     * @param  array<string, Node\Stmt\ClassLike> $classes
     * @return array<string, true>
     */
    private function plugins(array $classes): array
    {
        $result = [];
        $queue  = array_map(static fn(string $n): string => strtolower(strtr($n, ['\\' => '_'])), StringValues::classNames($this->ast));
        foreach ($classes as $name => $class) {
            if ($class->getAttribute('dynamicPlugin') === true) {
                $queue[] = (string) $name;
            }
        }

        while ($queue !== []) {
            $name  = array_pop($queue);
            $class = $classes[$name] ?? null;
            if (isset($result[$name]) || !$class instanceof Node\Stmt\Class_) {
                continue;
            }

            $result[$name] = true;
            if ($class->extends instanceof Node\Name) {
                $queue[] = strtolower(ltrim($class->extends->toString(), '\\'));
            }
        }

        return $result;
    }

    /**
     * Метод, который нельзя удалить при любом использовании имени.
     *
     * @param array<string, Node\Stmt\ClassLike> $classes
     */
    private function pinned(Node\Stmt\ClassMethod $method, Node\Stmt\ClassLike $class, array $classes): bool
    {
        // __call вызывается только для отсутствующего (или недоступного) метода — см. magicCallReachable
        if ($method->name->toLowerString() === '__call' && $class instanceof Node\Stmt\Class_ && !$this->magicCallReachable($class)) {
            return false;
        }

        if (str_starts_with($method->name->toString(), '__') || $method->attrGroups !== []) {
            return true;
        }

        return $this->declaredOutside($class, $method->name->toString(), $classes, 0);
    }

    /**
     * Может ли быть вызван __call класса: вызов метода, которого у объекта класса нет
     * (или он не публичный), на получателе, который может быть объектом класса или
     * наследника. Имя по выражению — по конечному набору значений (StringValues);
     * callable-массив `[$obj, 'm']` — как вызов.
     */
    private function magicCallReachable(Node\Stmt\Class_ $class): bool
    {
        if (!$class->name instanceof Node\Identifier || !$this->inference instanceof TypeInference || $this->extendedOutside($class)) {
            return true;
        }

        $values = new StringValues($this->ast);
        $self   = $class->name->toLowerString();
        $call   = $class->getMethod('__call');
        $accept = $call instanceof Node\Stmt\ClassMethod ? $this->acceptedPrefixes($call) : null;
        foreach ($this->findNode(Node::class) as $node) {
            [$receiver, $names] = match (true) {
                $node instanceof Node\Expr\MethodCall, $node instanceof Node\Expr\NullsafeMethodCall => [
                    $node->var,
                    $node->name instanceof Node\Identifier ? [$node->name->toString()] : $values->of($node->name),
                ],
                $node instanceof Node\Expr\Array_ && count($node->items) === 2 && $node->items[1]?->value instanceof Node\Scalar\String_
                    && $node->items[0] instanceof Node\ArrayItem => [$node->items[0]->value, [$node->items[1]->value->value]],
                default => [null, []],
            };
            if (!$receiver instanceof Node\Expr) {
                continue;
            }

            $missing = $names === null ? [null] : array_filter($names, fn(string $n): bool => !$this->publicMethod($class, $n));
            // __call, который для прочих имён только бросает исключение, — по префиксам
            if ($accept !== null) {
                $missing = array_filter($missing, static fn(?string $n): bool => $n === null
                    || array_any($accept, static fn(string $p): bool => str_starts_with(strtolower($n), $p)));
            }

            if ($missing === []) {
                continue;
            }

            // Получатель — объект этого класса? $this внутри него, тип, связанный иерархией
            $types = $this->inference->typeOf($receiver);
            if ($types === null) {
                return true;
            }

            foreach ($types as $type) {
                if (
                    $type === $self || isset($this->inference->ancestors($class)[$type])
                    || ($declared = $this->classes[$type] ?? null) instanceof Node\Stmt\ClassLike && isset($this->inference->ancestors($declared)[$self])
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Префиксы имён, которые __call обрабатывает: тело — `if (str_starts_with($name,
     * 'findBy')) { return …; }` … и в конце throw. Для прочих имён вызов — ошибка и без
     * __call. null — тело другого вида.
     *
     * @return list<string>|null lowercase префиксы
     */
    private function acceptedPrefixes(Node\Stmt\ClassMethod $call): ?array
    {
        $param = $call->params[0]->var ?? null;
        if (!$param instanceof Node\Expr\Variable || !is_string($param->name) || $call->stmts === null || $call->stmts === []) {
            return null;
        }

        $prefixes = [];
        $stmts    = array_values($call->stmts);
        $last     = array_pop($stmts);
        $throws   = $last instanceof Node\Stmt\Expression && $last->expr instanceof Node\Expr\Throw_;
        if (!$throws) {
            return null;
        }

        foreach ($stmts as $stmt) {
            $cond = $stmt instanceof Node\Stmt\If_ && $stmt->elseifs === [] && !$stmt->else instanceof \PhpParser\Node\Stmt\Else_ ? $stmt->cond : null;
            $args = $cond instanceof Node\Expr\FuncCall && $cond->name instanceof Node\Name
                && ltrim($cond->name->toLowerString(), '\\') === 'str_starts_with' && !$cond->isPartialFunctionApplication()
                ? $cond->getArgs() : null;
            if (
                $args === null || !($args[0] ?? null)?->value instanceof Node\Expr\Variable || $args[0]->value->name !== $param->name
                || !($args[1] ?? null)?->value instanceof Node\Scalar\String_
            ) {
                return null;
            }

            $prefixes[] = strtolower($args[1]->value->value);
        }

        return $prefixes;
    }

    /** Публичный метод объекта класса (свой или унаследованный, кроме __call). */
    private function publicMethod(Node\Stmt\Class_ $class, string $name): bool
    {
        $method = $this->inference?->lookup($class, $name);

        return $method instanceof Node\Stmt\ClassMethod && $method->isPublic() && $method->name->toLowerString() !== '__call';
    }

    private function extendedOutside(Node\Stmt\Class_ $class): bool
    {
        return $this->fileMap !== [] && $class->name instanceof Node\Identifier
            && ExternalCode::forProgram($this->fileMap, $this->classes, $this->ast)->extendsOrImplements($class->name->toString());
    }

    /**
     * Объявлен ли метод у внешнего предка (или предок неизвестен).
     *
     * @param array<string, Node\Stmt\ClassLike> $classes
     */
    private function declaredOutside(Node\Stmt\ClassLike $class, string $method, array $classes, int $depth): bool
    {
        if ($depth > 32) {
            return true;
        }

        $parents = match (true) {
            $class instanceof Node\Stmt\Class_ => [$class->extends, ...$class->implements],
            $class instanceof Node\Stmt\Interface_ => $class->extends,
            $class instanceof Node\Stmt\Enum_ => $class->implements,
            default => [],
        };
        if ($class instanceof Node\Stmt\Enum_ && in_array(strtolower($method), ['cases', 'from', 'tryfrom'], true)) {
            return true;
        }

        foreach ($parents as $parent) {
            if (!$parent instanceof Node\Name) {
                continue;
            }

            $name     = strtolower(ltrim($parent->toString(), '\\'));
            $declared = $classes[$name] ?? null;
            if ($declared instanceof Node\Stmt\ClassLike) {
                if ($this->declaredOutside($declared, $method, $classes, $depth + 1)) {
                    return true;
                }

                continue;
            }

            if (!class_exists($name) && !interface_exists($name)) {
                return true;
            }

            if (new \ReflectionClass($name)->hasMethod($method)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Имена методов (lowercase), упомянутые в коде, и префиксы составных строк.
     *
     * @param array<Node>          $nodes
     * @param array<string, true>  $live
     * @param array<string, true>  $prefixes
     * @param list<string>         $queue
     */
    private function collectNames(array $nodes, array &$live, array &$prefixes, array &$queue): void
    {
        $add = static function (string $name) use (&$live, &$queue): void {
            $name = strtolower($name);
            if ($name !== '' && !isset($live[$name])) {
                $live[$name] = true;
                $queue[]     = $name;
            }
        };

        foreach (new NodeFinder()->find($nodes, static fn(Node $n): bool => true) as $node) {
            if (
                ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\NullsafeMethodCall || $node instanceof Node\Expr\StaticCall)
                && $node->name instanceof Node\Identifier
            ) {
                $targets = $this->targets($node);
                if ($targets === null) {
                    $add($node->name->toString());
                } else {
                    array_map($this->markLive(...), $targets);
                }
            } elseif ($node instanceof Node\Scalar\String_) {
                // 'm', 'C::m', 'C:m' (нотация Slim)
                $value = $node->value;
                $add($value);
                $tail = strrchr($value, ':');
                if ($tail !== false) {
                    $add(substr($tail, 1));
                }
            } elseif ($node instanceof Node\Scalar\InterpolatedString) {
                $first = $node->parts[0] ?? null;
                if ($first instanceof Node\InterpolatedStringPart && $this->mayNameMethod($node, 0)) {
                    $this->addPrefix($first->value, $prefixes, $node->parts[1] ?? null);
                }
            } elseif ($node instanceof Node\Expr\BinaryOp\Concat) {
                // Учитывается только литеральное начало: имя, собранное с конца
                // (`$x . 'Suffix'`), не видно — то же допущение, что и для классов
                $left = $node->left;
                while ($left instanceof Node\Expr\BinaryOp\Concat) {
                    $left = $left->left;
                }

                if ($left instanceof Node\Scalar\String_ && !$node->getAttribute('parent') instanceof Node\Expr\BinaryOp\Concat && $this->mayNameMethod($node, 0)) {
                    $next = $left->getAttribute('parent');
                    $this->addPrefix($left->value, $prefixes, $next instanceof Node\Expr\BinaryOp\Concat ? $next->right : null);
                }
            } elseif ($node instanceof Node\Stmt\TraitUseAdaptation\Alias) {
                $add($node->method->toString());
            }
        }
    }

    /**
     * Методы, которые может вызвать узел, по типу получателя; null — тип неизвестен.
     *
     * @return list<Node\Stmt\ClassMethod>|null
     */
    private function targets(Node\Expr\MethodCall|Node\Expr\NullsafeMethodCall|Node\Expr\StaticCall $call): ?array
    {
        $name    = $call->name instanceof Node\Identifier ? $call->name->toLowerString() : '';
        $context = $this->enclosingClass($call);

        if ($call instanceof Node\Expr\StaticCall) {
            if (!$call->class instanceof Node\Name) {
                return null;
            }

            $class = $call->class->toLowerString();

            return match ($class) {
                'self' => $context instanceof Node\Stmt\Class_ ? $this->resolve([$context], $name, false) : null,
                'static' => $context instanceof Node\Stmt\Class_ ? $this->resolve([$context], $name, true) : null,
                'parent' => $context instanceof Node\Stmt\Class_ && $context->extends instanceof Node\Name && ($parent = $this->classes[strtolower(ltrim($context->extends->toString(), '\\'))] ?? null) instanceof Node\Stmt\Class_
                    ? $this->resolve([$parent], $name, false)
                    : null,
                default => ($named = $this->classes[ltrim($class, '\\')] ?? null) instanceof Node\Stmt\Class_ ? $this->resolve([$named], $name, false) : null,
            };
        }

        $receiver = $call->var;
        if ($receiver instanceof Node\Expr\New_) {
            $class = $receiver->class instanceof Node\Name ? $this->classes[strtolower(ltrim($receiver->class->toString(), '\\'))] ?? null : null;

            return $class instanceof Node\Stmt\Class_ ? $this->resolve([$class], $name, false) : null;
        }

        // $this в замыкании может быть привязан к другому объекту
        $function = $this->enclosingFunction($call);
        if ($function instanceof Node\Expr\Closure && !$function->static) {
            return null;
        }

        if ($receiver instanceof Node\Expr\Variable && $receiver->name === 'this') {
            return $context instanceof Node\Stmt\Class_ ? $this->resolve([$context], $name, true) : null;
        }

        $types = $this->inference?->typeOf($receiver);
        if ($types === null) {
            return null;
        }

        $result = [];
        foreach ($types as $type) {
            $subtypes = $this->subtypes($type);
            if ($subtypes === null) {
                return null;
            }

            $found = $this->resolve($subtypes, $name, false, true);
            if ($found === null) {
                return null;
            }

            array_push($result, ...$found);
        }

        return $result;
    }

    /**
     * Метод по имени для объектов перечисленных классов: найденный вверх по предкам и,
     * если $overrides, переопределения в наследниках. null — у какого-то класса метода
     * нет в программе (внешний предок, __call) — решать по имени.
     *
     * @param  list<Node\Stmt\Class_> $classes
     * @return list<Node\Stmt\ClassMethod>|null
     */
    private function resolve(array $classes, string $name, bool $overrides, bool $concreteOnly = false): ?array
    {
        $result = [];
        foreach ($classes as $class) {
            if ($concreteOnly && $class->isAbstract()) {
                continue;
            }

            $method = $this->lookup($class, $name);
            if (!$method instanceof Node\Stmt\ClassMethod) {
                return null;
            }

            $result[] = $method;
            if ($overrides && $class->name instanceof Node\Identifier) {
                foreach ($this->subtypes($class->name->toLowerString()) ?? [] as $child) {
                    $override = $child->getMethod($name);
                    if ($override instanceof Node\Stmt\ClassMethod) {
                        $result[] = $override;
                    }
                }
            }
        }

        return $result;
    }

    private function lookup(Node\Stmt\Class_ $class, string $name): ?Node\Stmt\ClassMethod
    {
        $seen = [];
        for ($current = $class; $current instanceof Node\Stmt\Class_;) {
            $method = $current->getMethod($name);
            if ($method instanceof Node\Stmt\ClassMethod) {
                return $method;
            }

            // Трейт не встроен — его методы не видны
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

    /**
     * Классы программы с типом $type (сам класс, наследники, реализации интерфейса);
     * null — тип не из программы или его наследует анонимный класс.
     *
     * @return list<Node\Stmt\Class_>|null
     */
    private function subtypes(string $type): ?array
    {
        return $this->inference?->subtypes($type);
    }

    /** @return array<string, true> lowercase имена класса и всех его предков в программе */
    private function ancestorsOf(Node\Stmt\ClassLike $class): array
    {
        $key = spl_object_id($class);
        if (isset($this->ancestry[$key])) {
            return $this->ancestry[$key];
        }

        $result = $class->name instanceof Node\Identifier ? [$class->name->toLowerString() => true] : [];
        $queue  = [$class];
        while ($queue !== []) {
            $current = array_pop($queue);
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

    private function enclosingClass(Node $node): ?Node\Stmt\ClassLike
    {
        for ($current = $node->getAttribute('parent'); $current instanceof Node; $current = $current->getAttribute('parent')) {
            if ($current instanceof Node\Stmt\ClassLike) {
                return $current;
            }
        }

        return null;
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

    private function ownerOf(Node\Stmt\ClassMethod $method): ?Node\Stmt\ClassLike
    {
        $parent = $method->getAttribute('parent');

        return $parent instanceof Node\Stmt\ClassLike ? $parent : null;
    }

    /**
     * Может ли значение строки стать именем метода: имя динамического вызова, элемент
     * callable-массива, аргумент call_user_func()/method_exists() или пользовательской
     * функции, результат (return). Значение, присвоенное переменной, — по её
     * использованиям в той же функции. Строка для вывода, сравнения, ключа массива или
     * встроенной функции (`'t' . $counter` — псевдоним таблицы в SQL) — нет.
     */
    private function mayNameMethod(Node\Expr $expr, int $depth): bool
    {
        if ($depth > 400) {
            return true;
        }

        $key = spl_object_id($expr);
        if (isset($this->namesMethod[$key])) {
            return $this->namesMethod[$key];
        }

        // Уже вычисляется (цикл `$sql = f($sql)`): сам цикл имени не даёт — «нет»; ответ
        // «нет», полученный через такое допущение, не кэшируется
        if (isset($this->inProgress[$key])) {
            $this->assumed++;

            return false;
        }

        $this->inProgress[$key] = true;
        $assumed                = $this->assumed;
        $result                 = $this->computeMayNameMethod($expr, $depth);
        unset($this->inProgress[$key]);
        if ($result || $this->assumed === $assumed) {
            $this->namesMethod[$key] = $result;
        }

        return $result;
    }

    private function computeMayNameMethod(Node\Expr $expr, int $depth): bool
    {
        $current = $expr;
        $parent  = $current->getAttribute('parent');
        while (
            $parent instanceof Node\Expr\BinaryOp\Concat || $parent instanceof Node\Expr\BinaryOp\Coalesce
            || $parent instanceof Node\Expr\Ternary && $parent->cond !== $current || $parent instanceof Node\Expr\Cast\String_
            || $parent instanceof Node\MatchArm && $parent->body === $current || $parent instanceof Node\Expr\Match_ && $parent->cond !== $current
        ) {
            // Не в начале конкатенации — строка начинается с другого
            if ($parent instanceof Node\Expr\BinaryOp\Concat && $parent->right === $current) {
                return false;
            }

            $current = $parent;
            $parent  = $current->getAttribute('parent');
        }

        if ($parent instanceof Node\Arg) {
            $call = $parent->getAttribute('parent');
            if ($call instanceof Node\Expr\FuncCall && $call->name instanceof Node\Name && function_exists($call->name->toString())) {
                return in_array(
                    $call->name->toLowerString(),
                    ['call_user_func', 'call_user_func_array', 'method_exists', 'is_callable', 'array_map', 'usort', 'uasort', 'uksort', 'array_filter', 'array_walk'],
                    true,
                ) || !new \ReflectionFunction($call->name->toString())->isInternal() && $this->argumentMayNameMethod($parent, $depth);
            }

            return $this->argumentMayNameMethod($parent, $depth);
        }

        return match (true) {
            $parent instanceof Node\Expr\Assign && $parent->expr === $current => $this->variableMayNameMethod($parent, $depth),
            $parent instanceof Node\Scalar\InterpolatedString => $parent->parts[0] === $current && $this->mayNameMethod($parent, $depth + 1),
            $parent instanceof Node\ArrayItem => $parent->key !== $current && $this->callableArray($parent),
            $parent instanceof Node\Expr\ArrayDimFetch, $parent instanceof Node\Stmt\Echo_, $parent instanceof Node\Expr\Print_,
            $parent instanceof Node\Expr\BinaryOp, $parent instanceof Node\Expr\Isset_, $parent instanceof Node\Stmt\Expression,
            $parent instanceof Node\Expr\Throw_, $parent instanceof Node\InterpolatedStringPart, $parent instanceof Node\Expr\BooleanNot,
            $parent instanceof Node\Expr\Empty_, $parent instanceof Node\Expr\Instanceof_,
            $parent instanceof Node\Stmt\If_, $parent instanceof Node\Stmt\ElseIf_, $parent instanceof Node\Stmt\While_,
            $parent instanceof Node\Stmt\Switch_, $parent instanceof Node\Expr\Match_, $parent instanceof Node\MatchArm,
            $parent instanceof Node\Expr\Ternary, $parent instanceof Node\Expr\Cast, $parent instanceof Node\Expr\PropertyFetch,
            $parent instanceof Node\Expr\NullsafePropertyFetch, $parent instanceof Node\Expr\New_ => false,
            $parent instanceof Node\Expr\MethodCall, $parent instanceof Node\Expr\NullsafeMethodCall, $parent instanceof Node\Expr\StaticCall
                => $parent->name === $current,
            // `$x .= $value` — дописано в конец; `$value .= …` — начало прежнее, проверяются другие использования
            $parent instanceof Node\Expr\AssignOp\Concat => false,
            $parent instanceof Node\Expr\AssignOp => $parent->var !== $current,
            $parent instanceof Node\Stmt\Return_ => $this->resultMayNameMethod($parent, $depth),
            $parent instanceof Node\Stmt\Foreach_ => $parent->expr !== $current || $this->foreachMayNameMethod($parent, $depth),
            $parent instanceof Node\Expr\ArrowFunction => $this->callbackResultMayNameMethod($parent, $depth),
            default => true,
        };
    }

    /**
     * Значение, записанное в свойство: по всем чтениям свойства с этим именем (мир
     * замкнут, имена классов плоские — внешний код свойств не читает).
     */
    private function propertyMayNameMethod(string $name, int $depth): bool
    {
        if ($this->propertyReads === null) {
            $this->propertyReads = [];
            $fetches = new NodeFinder()->find($this->ast, static fn(Node $n): bool => $n instanceof Node\Expr\PropertyFetch
                || $n instanceof Node\Expr\NullsafePropertyFetch || $n instanceof Node\Expr\StaticPropertyFetch);
            foreach ($fetches as $fetch) {
                assert($fetch instanceof Node\Expr\PropertyFetch || $fetch instanceof Node\Expr\NullsafePropertyFetch || $fetch instanceof Node\Expr\StaticPropertyFetch);
                if ($fetch->name instanceof Node\Identifier) {
                    $this->propertyReads[$fetch->name->toString()][] = $fetch;
                } elseif ($fetch->name instanceof Node\Scalar\String_) {
                    $this->propertyReads[$fetch->name->value][] = $fetch;
                } else {
                    $this->propertyReads[''][] = $fetch; // $obj->$name — читает любое свойство
                }
            }
        }

        $reads = [...$this->propertyReads[$name] ?? [], ...$this->propertyReads[''] ?? []];
        if (count($reads) > 400) {
            return true;
        }

        foreach ($reads as $read) {
            // Чтение — выражение вместе с индексами: `$this->map[$k]`
            $value  = $read;
            $parent = $value->getAttribute('parent');
            while ($parent instanceof Node\Expr\ArrayDimFetch && $parent->var === $value) {
                $value  = $parent;
                $parent = $value->getAttribute('parent');
            }

            $written = $parent instanceof Node\Expr\Assign && $parent->var === $value
                || ($parent instanceof Node\Stmt\Unset_ || $parent instanceof Node\Expr\Isset_);
            if (!$written && $this->mayNameMethod($value, $depth + 1)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Результат стрелочной функции — колбэка встроенной функции: попадает в её
     * результат (array_map, preg_replace_callback) или только сравнивается (usort).
     */
    private function callbackResultMayNameMethod(Node\Expr\ArrowFunction $function, int $depth): bool
    {
        $arg  = $function->getAttribute('parent');
        $call = $arg instanceof Node\Arg ? $arg->getAttribute('parent') : null;
        if (!$call instanceof Node\Expr\FuncCall || !$call->name instanceof Node\Name || !function_exists($call->name->toString())) {
            return true;
        }

        return match ($call->name->toLowerString()) {
            'array_map', 'preg_replace_callback', 'preg_replace_callback_array', 'array_reduce', 'array_find' => $this->mayNameMethod($call, $depth + 1),
            'usort', 'uasort', 'uksort', 'array_filter', 'array_walk', 'array_any', 'array_all', 'array_find_key' => false,
            default => true,
        };
    }

    /** Обход массива: по использованиям переменных ключа и значения. */
    private function foreachMayNameMethod(Node\Stmt\Foreach_ $foreach, int $depth): bool
    {
        $scope = $this->enclosingFunction($foreach);
        foreach ([$foreach->keyVar, $foreach->valueVar] as $var) {
            if (!$var instanceof \PhpParser\Node\Expr) {
                continue;
            }

            if (!$var instanceof Node\Expr\Variable || !is_string($var->name) || !$scope instanceof Node\FunctionLike) {
                return true;
            }

            if ($this->usesMayNameMethod($scope, $var->name, $depth + 1)) {
                return true;
            }
        }

        return false;
    }

    /** Результат функции или метода: по местам вызова (все известны — мир замкнут). */
    private function resultMayNameMethod(Node\Stmt\Return_ $return, int $depth): bool
    {
        $scope = $this->enclosingFunction($return);
        if (!$scope instanceof Node\Stmt\ClassMethod && !$scope instanceof Node\Stmt\Function_) {
            return true;
        }

        if ($this->callSites === null) {
            $this->callSites = [];
            foreach ($this->findNode(Node\Expr\CallLike::class) as $call) {
                $name = $call instanceof Node\Expr\FuncCall ? $call->name : ($call instanceof Node\Expr\New_ ? null : $call->name ?? null);
                if ($name instanceof Node\Name || $name instanceof Node\Identifier) {
                    $this->callSites[strtolower(ltrim($name->toString(), '\\'))][] = $call;
                }
            }
        }

        $sites = $this->callSites[$scope->name->toLowerString()] ?? [];
        if (count($sites) > 100) {
            return true;
        }

        foreach ($sites as $site) {
            if ($site instanceof Node\Expr\FuncCall !== $scope instanceof Node\Stmt\Function_ || $site->isFirstClassCallable()) {
                if ($site->isFirstClassCallable()) {
                    return true;
                }

                continue;
            }

            if ($this->mayNameMethod($site, $depth + 1)) {
                return true;
            }
        }

        return false;
    }

    private function isAssignTarget(Node\Expr $expr): bool
    {
        $parent = $expr->getAttribute('parent');

        return $parent instanceof Node\Expr\Assign && $parent->var === $expr || $parent instanceof Node\Stmt\Foreach_ && $parent->valueVar === $expr;
    }

    /** Элемент массива из двух элементов (`[$obj, 'm' . $x]`) — возможный callable. */
    private function callableArray(Node\ArrayItem $item): bool
    {
        $array = $item->getAttribute('parent');

        // [$a, $b] = … — запись в переменные, а не массив
        return !$array instanceof Node\Expr\List_ && (!$array instanceof Node\Expr\Array_ || count($array->items) === 2 && !$this->isAssignTarget($array));
    }

    /** Присвоено переменной, свойству или элементу: может ли значение стать именем метода. */
    private function variableMayNameMethod(Node\Expr\Assign $assign, int $depth): bool
    {
        $target = $assign->var;
        while ($target instanceof Node\Expr\ArrayDimFetch) {
            $target = $target->var;
        }

        if ($target instanceof Node\Expr\PropertyFetch || $target instanceof Node\Expr\StaticPropertyFetch) {
            return !$target->name instanceof Node\Identifier
                || $this->propertyMayNameMethod($target->name->toString(), $depth);
        }

        // [$sql, $params] = …: по каждой переменной списка
        if ($target instanceof Node\Expr\List_ || $target instanceof Node\Expr\Array_) {
            $scope = $this->enclosingFunction($assign);
            foreach ($target->items as $item) {
                if ($item === null) {
                    continue;
                }

                if (!$item->value instanceof Node\Expr\Variable || !is_string($item->value->name) || !$scope instanceof Node\FunctionLike) {
                    return true;
                }

                if ($this->usesMayNameMethod($scope, $item->value->name, $depth + 1)) {
                    return true;
                }
            }

            return false;
        }

        if ($target !== $assign->var && $target instanceof Node\Expr\Variable && is_string($target->name)) {
            $scope = $this->enclosingFunction($assign);

            return !$scope instanceof Node\FunctionLike || $this->usesMayNameMethod($scope, $target->name, $depth + 1);
        }

        if (!$assign->var instanceof Node\Expr\Variable || !is_string($assign->var->name)) {
            return true; // список, динамическая переменная — дальнейший путь не отслеживается
        }

        $scope = $this->enclosingFunction($assign);

        return !$scope instanceof Node\FunctionLike || $this->usesMayNameMethod($scope, $assign->var->name, $depth);
    }

    /**
     * Аргумент вызова функции или метода программы с известной целью: по
     * использованиям параметра в каждой возможной цели.
     */
    private function argumentMayNameMethod(Node\Arg $arg, int $depth): bool
    {
        $call     = $arg->getAttribute('parent');
        $callees  = $this->callees($call);
        $position = $call instanceof Node\Expr\CallLike ? array_search($arg, $call->args, true) : false;
        if ($callees === []) {
            return false; // встроенный метод или класс: методы программы по имени не вызывает
        }

        if ($callees === null || !is_int($position) || $arg->unpack || $arg->name instanceof Node\Identifier) {
            return true;
        }

        foreach ($callees as $callee) {
            $param = $callee->params[$position] ?? null;
            // Лишний аргумент функция видит только через func_get_args()
            if (
                !$param instanceof Node\Param && array_all($callee->getParams(), static fn(Node\Param $p): bool => !$p->variadic)
                && !new NodeFinder()->findFirst($callee->getStmts() ?? [], static fn(Node $n): bool => $n instanceof Node\Expr\FuncCall
                    && $n->name instanceof Node\Name && in_array($n->name->toLowerString(), ['func_get_args', 'func_get_arg'], true)) instanceof \PhpParser\Node
            ) {
                continue;
            }

            if (
                !$param instanceof Node\Param || $param->variadic || $param->byRef || !$param->var instanceof Node\Expr\Variable
                || !is_string($param->var->name) || $this->usesMayNameMethod($callee, $param->var->name, $depth + 1)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Функции и методы программы, которые может вызвать узел; [] — только встроенный
     * код (он не вызывает методы программы по имени, кроме рефлексии); null — неизвестно.
     * Получатель неизвестен — все методы программы с этим именем (мир замкнут).
     *
     * @return list<Node\FunctionLike>|null
     */
    private function callees(mixed $call): ?array
    {
        if ($call instanceof Node\Expr\New_) {
            if (!$call->class instanceof Node\Name) {
                return null;
            }

            $name  = strtolower(ltrim($call->class->toString(), '\\'));
            $class = $this->classes[$name] ?? null;
            if (!$class instanceof Node\Stmt\Class_) {
                return class_exists($name) && !$this->reflective($name) ? [] : null;
            }

            $constructor = $this->lookup($class, '__construct');

            // Конструктора в программе нет — встроенный предка (Exception) или никакого
            return $constructor instanceof Node\Stmt\ClassMethod ? [$constructor] : [];
        }

        if ($call instanceof Node\Expr\FuncCall && $call->name instanceof Node\Name) {
            if ($this->functions === null) {
                $this->functions = [];
                foreach ($this->findNode(Node\Stmt\Function_::class) as $function) {
                    $this->functions[$function->name->toLowerString()][] = $function;
                }
            }

            $found = $this->functions[strtolower(ltrim($call->name->toString(), '\\'))] ?? [];

            return count($found) === 1 ? $found : null;
        }

        if (
            ($call instanceof Node\Expr\MethodCall || $call instanceof Node\Expr\NullsafeMethodCall || $call instanceof Node\Expr\StaticCall)
            && $call->name instanceof Node\Identifier
        ) {
            $targets = $this->targets($call);
            if ($targets !== null && $targets !== []) {
                return $targets;
            }

            $named = $this->allMethods()[$call->name->toLowerString()] ?? [];

            return $named !== [] ? $named : ($this->reflective($call->name->toString()) ? null : []);
        }

        return null;
    }

    /** Рефлексия и колбэки: по имени вызывают произвольные методы. */
    private function reflective(string $name): bool
    {
        return preg_match('/reflection|closure|callable|invoke|call|getmethod|fromcallable/i', $name) === 1;
    }

    /** @return array<string, list<Node\Stmt\ClassMethod>> все методы программы по lowercase имени */
    private function allMethods(): array
    {
        if ($this->allMethods === null) {
            $this->allMethods = [];
            foreach ($this->classes as $class) {
                foreach ($class->getMethods() as $method) {
                    $this->allMethods[$method->name->toLowerString()][] = $method;
                }
            }
        }

        return $this->allMethods;
    }

    /** Может ли локальная переменная (или параметр) функции стать именем метода. */
    private function usesMayNameMethod(Node\FunctionLike $scope, string $name, int $depth): bool
    {
        foreach (new NodeFinder()->find($scope->getStmts() ?? [], static fn(Node $n): bool => $n instanceof Node\Expr\Variable && $n->name === $name) as $use) {
            assert($use instanceof Node\Expr\Variable);
            $parent = $use->getAttribute('parent');
            if ($parent instanceof Node\Expr\Assign && $parent->var === $use) {
                continue;
            }

            if (
                ($parent instanceof Node\Expr\MethodCall || $parent instanceof Node\Expr\StaticCall || $parent instanceof Node\Expr\NullsafeMethodCall)
                && $parent->name === $use || $parent instanceof Node\Expr\ClosureUse || $this->mayNameMethod($use, $depth + 1)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Начало имени метода: только то, что может начинать идентификатор
     * (`'SELECT ' . $x` методом стать не может).
     *
     * @param array<string, true> $prefixes
     */
    private function addPrefix(string $value, array &$prefixes, ?Node $next = null): void
    {
        if (preg_match('/^[a-z_\x80-\xff][a-z0-9_\x80-\xff]*$/i', $value) !== 1) {
            return;
        }

        // 'e' . $counter++ — дальше только цифры: имя вида e0, e1, …
        if ($next instanceof Node\Expr && $this->isNumeric($next)) {
            foreach (range(0, 9) as $digit) {
                $prefixes[strtolower($value) . $digit] = true;
            }

            return;
        }

        $prefixes[strtolower($value)] = true;
    }

    /** Выражение даёт число (его строка — цифры, возможно со знаком минус). */
    private function isNumeric(Node\Expr $expr): bool
    {
        if ($expr instanceof Node\Expr\Variable && is_string($expr->name)) {
            $function = $this->enclosingFunction($expr);
            foreach ($function instanceof Node\FunctionLike ? $function->getParams() : [] as $param) {
                if ($param->var instanceof Node\Expr\Variable && $param->var->name === $expr->name) {
                    return $param->type instanceof Node\Identifier && $param->type->toLowerString() === 'int';
                }
            }

            return false;
        }

        return match (true) {
            $expr instanceof Node\Scalar\Int_, $expr instanceof Node\Expr\PostInc, $expr instanceof Node\Expr\PreInc,
            $expr instanceof Node\Expr\PostDec, $expr instanceof Node\Expr\PreDec, $expr instanceof Node\Expr\Cast\Int_ => true,
            $expr instanceof Node\Expr\FuncCall => $expr->name instanceof Node\Name
                && in_array($expr->name->toLowerString(), ['count', 'strlen', 'intval', 'spl_object_id', 'mt_rand', 'random_int', 'crc32'], true),
            $expr instanceof Node\Expr\BinaryOp\Plus, $expr instanceof Node\Expr\BinaryOp\Minus, $expr instanceof Node\Expr\BinaryOp\Mul
                => $this->isNumeric($expr->left) && $this->isNumeric($expr->right),
            default => false,
        };
    }

    /** @param array<string, true> $prefixes */
    private function matchesPrefix(string $name, array $prefixes): bool
    {
        // Магические методы вызывает PHP, а не собранная строка
        if (str_starts_with($name, '__')) {
            return false;
        }

        for ($length = strlen($name); $length > 0; $length--) {
            if (isset($prefixes[substr($name, 0, $length)])) {
                return true;
            }
        }

        return false;
    }
}
