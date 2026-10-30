<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;
use Recombinator\Support\Sandbox;

/**
 * Подставляет литеральные записи DI-контейнера там, где их читают:
 *
 *   $cnt = new Container(['settings' => ['doctrine' => ['dev_mode' => true, ...]]]);
 *   $cnt->set('EM', static function ($c) { $s = $c->get('settings'); ... });
 *   →
 *   $cnt->set('EM', static function ($c) { $s = ['doctrine' => ['dev_mode' => true, ...]]; ... });
 *
 * Фабрики вызываются позже (из фреймворка), поэтому по одному месту создания
 * значение не доказать. Нужно, чтобы запись не перезаписывалась никогда:
 *
 * 1. Класс контейнера хранит записи в закрытом массиве P: конструктор кладёт туда
 *    свой аргумент, все записи — `$this->P[$param] = ...` в методах-«писателях»;
 *    запись в get() после вызова записи как фабрики (запоминание) записи-не-фабрики
 *    не касается. get($id) возвращает `$this->P[$id]`, а ветка вызова фабрики
 *    срабатывает только для `\Closure`.
 * 2. Ни один вызов «писателя» (set, factory) с этим ключом или с вычисляемым
 *    ключом не может прийтись на контейнер. Получатель отсеивается по объявленным
 *    типам (исходным — StripLocalClassTypeHints сохраняет их в атрибутах): PHP
 *    проверяет типы свойств и параметров при выполнении, и свойство
 *    `Collection $collection` контейнер содержать не может.
 * 3. Читаются записи на самом контейнере верхнего уровня или в первом параметре
 *    фабрик, сохранённых в него там же.
 *
 * Подставляется только значение без побочных эффектов (литералы, константы):
 * оно вычисляется при каждом чтении, а не один раз при создании контейнера.
 *
 * Допущение окружения containerEntriesImmutable (TargetEnvironment) снимает условие 2
 * для вызовов, которые анализ не может отсеять: с динамическим именем метода и с
 * вычисляемым ключом. Вызовы писателя с литеральным ключом учитываются как обычно.
 */
#[VisitorMeta('Литеральные записи DI-контейнера: $c->get("settings") → значение')]
class ContainerEntryVisitor extends BaseVisitor
{
    /** @var array<string, Node\Expr> ключ → литеральное значение записи */
    private array $entries = [];

    private ?string $getter = null;

    /** @var array<string, Node\Stmt\ClassLike>|null */
    private ?array $classes = null;

    /** @var array<string, bool> */
    private array $ancestry = [];

    /** @var array<string, list<string>|null> выведенные типы выражений (см. receiverClasses) */
    private array $types = [];

    /** @var array<string, bool> результаты анализа по запросу (см. mayBeContainer) */
    private array $memo = [];

    /** @var list<string> ключи, получившие промежуточное «нет» в текущем запросе */
    private array $journal = [];

    private int $active = 0;

    /**
     * Индекс мест вызова и записей (см. buildIndex).
     *
     * @var array{
     *     calls: array<string, list<Node\Expr\MethodCall|Node\Expr\NullsafeMethodCall|Node\Expr\StaticCall>>,
     *     dynamicCalls: list<Node\Expr\MethodCall|Node\Expr\NullsafeMethodCall|Node\Expr\StaticCall>,
     *     funcs: array<string, list<Node\Expr\FuncCall>>,
     *     news: array<string, list<Node\Expr\New_>>,
     *     dynamicNews: list<Node\Expr\New_>,
     *     anonNews: array<int, list<Node\Expr\New_>>,
     *     strings: array<string, true>,
     *     callables: array<string, true>,
     *     methods: array<string, list<Node\Stmt\ClassMethod>>,
     *     functions: array<string, list<Node\Stmt\Function_>>,
     *     propWrites: array<string, list<Node\Expr\Assign|Node\Expr\AssignRef|Node\Expr\AssignOp\Coalesce>>,
     *     elementWrites: array<string, list<Node\Expr\Assign|Node\Expr\AssignRef|Node\Expr\AssignOp\Coalesce>>,
     *     staticWrites: array<string, list<Node\Expr\Assign|Node\Expr\AssignRef|Node\Expr\AssignOp\Coalesce>>,
     *     promoted: array<string, list<array{Node\Stmt\ClassMethod, int}>>,
     * }
     */
    private array $index = [
        'calls' => [], 'dynamicCalls' => [], 'funcs' => [], 'news' => [], 'dynamicNews' => [], 'anonNews' => [], 'strings' => [],
        'callables' => [], 'methods' => [], 'functions' => [], 'propWrites' => [], 'elementWrites' => [], 'staticWrites' => [], 'promoted' => [],
    ];

    /** Переменная контейнера на верхнем уровне */
    private ?string $topVar = null;

    /** @var \SplObjectStorage<Node\FunctionLike, string> фабрика контейнера → имя параметра-контейнера */
    private \SplObjectStorage $factories;

    public function __construct(private readonly bool $assumeImmutable = false)
    {
        $this->factories = new \SplObjectStorage();
    }

    #[\Override]
    public function beforeTraverse(array $nodes): ?array
    {
        parent::beforeTraverse($nodes);
        $this->entries = [];
        $this->getter    = null;
        $this->topVar    = null;
        $this->factories = new \SplObjectStorage();

        if ($this->hasPendingIncludes()) {
            return null;
        }

        $counts = ObjectMethodInlinerVisitor::topLevelAssignCounts($nodes);
        foreach ($nodes as $stmt) {
            if (
                $stmt instanceof Node\Stmt\Expression && $stmt->expr instanceof Node\Expr\Assign
                && $stmt->expr->var instanceof Node\Expr\Variable && is_string($stmt->expr->var->name)
                && ($counts[$stmt->expr->var->name] ?? 0) === 1
                && $stmt->expr->expr instanceof Node\Expr\New_ && $stmt->expr->expr->class instanceof Node\Name
                && ($stmt->expr->expr->args[0] ?? null) instanceof Node\Arg
                && $stmt->expr->expr->args[0]->value instanceof Node\Expr\Array_
            ) {
                if ($this->analyze($stmt->expr->var->name, $stmt->expr->expr->class->toString(), $stmt->expr->expr->args[0]->value)) {
                    break;
                }

                $this->entries = [];
            }
        }

        return null;
    }

    public function enterNode(Node $node): ?Node
    {
        if (
            $this->entries === []
            || !$node instanceof Node\Expr\MethodCall
            || !$node->name instanceof Node\Identifier
            || strtolower($node->name->toString()) !== $this->getter
            || !($node->args[0] ?? null) instanceof Node\Arg
            || !$node->args[0]->value instanceof Node\Scalar\String_
            || !$this->isContainer($node->var)
        ) {
            return null;
        }

        $value = $this->entries[$node->args[0]->value->value] ?? null;

        return $value instanceof Node\Expr ? $this->deepClone($value) : null;
    }

    /**
     * Получатель — точно этот контейнер (а не любая переменная с тем же именем):
     * $cnt на верхнем уровне или параметр фабрики, сохранённой в контейнер.
     */
    private function isContainer(Node\Expr $expr): bool
    {
        if (!$expr instanceof Node\Expr\Variable || !is_string($expr->name)) {
            return false;
        }

        $function = $this->enclosingFunction($expr);
        if (!$function instanceof \PhpParser\Node\FunctionLike) {
            return $expr->name === $this->topVar && !$this->enclosingClass($expr) instanceof \PhpParser\Node\Stmt\ClassLike;
        }

        if (!isset($this->factories[$function]) || $this->factories[$function] !== $expr->name) {
            return false;
        }

        // Параметр не переприсвоен внутри фабрики
        return !array_any(
            $this->findNode(Node\Expr\Assign::class, $function->getStmts() ?? []),
            static fn(Node\Expr\Assign $a): bool => $a->var instanceof Node\Expr\Variable && $a->var->name === $expr->name,
        );
    }

    private function analyze(string $var, string $className, Node\Expr\Array_ $literal): bool
    {
        $class = null;
        foreach ($this->findNode(Node\Stmt\Class_::class) as $candidate) {
            if ($candidate->name instanceof Node\Identifier && strcasecmp($candidate->name->toString(), $className) === 0) {
                $class = $candidate;
            }
        }

        if (!$class instanceof Node\Stmt\Class_) {
            return false;
        }

        $store = $this->storeProperty($class);
        if ($store === null) {
            return false;
        }

        $writers = $this->writers($class, $store);
        $getter  = $this->getterMethod($class, $store);
        if ($writers === null || $getter === null) {
            return false;
        }

        foreach ($literal->items as $item) {
            if (
                $item instanceof Node\ArrayItem && $item->key instanceof Node\Scalar\String_ && !$item->byRef && !$item->unpack
                && $this->isConstantValue($item->value)
            ) {
                $this->entries[$item->key->value] = $item->value;
            }
        }

        $this->collectFactories($var, $writers);
        $this->topVar = $var;
        $this->memo    = [];
        $this->classes = null;
        $this->types    = [];
        $this->ancestry = [];
        $this->buildIndex();
        $written = $this->writtenKeys($class, $writers);
        if ($written === null) {
            return false;
        }

        $this->entries = array_diff_key($this->entries, $written);
        $this->getter  = $getter;
        $this->topVar  = $var;

        return $this->entries !== [];
    }

    /** Свойство, которому конструктор присваивает свой первый параметр. */
    private function storeProperty(Node\Stmt\Class_ $class): ?string
    {
        $ctor  = $class->getMethod('__construct');
        $param = $ctor?->params[0]->var ?? null;
        if (!$param instanceof Node\Expr\Variable || !is_string($param->name)) {
            return null;
        }

        foreach ($ctor->stmts ?? [] as $stmt) {
            if (
                $stmt instanceof Node\Stmt\Expression && $stmt->expr instanceof Node\Expr\Assign
                && $this->isThisProp($stmt->expr->var) && $stmt->expr->expr instanceof Node\Expr\Variable
                && $stmt->expr->expr->name === $param->name
            ) {
                $prop = $stmt->expr->var;
                assert($prop instanceof Node\Expr\PropertyFetch && $prop->name instanceof Node\Identifier);
                $property = $class->getProperty($prop->name->toString());

                return $property instanceof Node\Stmt\Property && $property->isPrivate() ? $prop->name->toString() : null;
            }
        }

        return null;
    }

    /**
     * Методы, пишущие в P, и индекс параметра-ключа. Запоминание в get (запись после
     * вызова самой записи как фабрики) не считается. null — запись другой формы.
     *
     * @return array<string, int>|null
     */
    private function writers(Node\Stmt\Class_ $class, string $store): ?array
    {
        $writers = [];
        foreach ($class->getMethods() as $method) {
            $params = [];
            foreach ($method->params as $i => $param) {
                if ($param->var instanceof Node\Expr\Variable && is_string($param->var->name)) {
                    $params[$param->var->name] = $i;
                }
            }

            $callsEntry = false;
            foreach ($this->findNode(Node::class, $method->stmts ?? []) as $node) {
                if (
                    $node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name
                    && strtolower($node->name->toString()) === 'call_user_func'
                    && ($node->args[0] ?? null) instanceof Node\Arg && $this->isStoreElement($node->args[0]->value, $store)
                ) {
                    $callsEntry = true;
                }

                $target = match (true) {
                    $node instanceof Node\Expr\Assign, $node instanceof Node\Expr\AssignOp, $node instanceof Node\Expr\AssignRef => $node->var,
                    default => null,
                };
                if ($node instanceof Node\Stmt\Unset_) {
                    foreach ($node->vars as $unset) {
                        if ($this->isStoreElement($unset, $store) || $this->isStore($unset, $store)) {
                            return null;
                        }
                    }
                }

                if (!$target instanceof \PhpParser\Node\Expr) {
                    continue;
                }

                if ($this->isStore($target, $store)) {
                    if ($method->name->toLowerString() !== '__construct') {
                        return null;
                    }

                    continue;
                }

                if (!$this->isStoreElement($target, $store)) {
                    continue;
                }

                assert($target instanceof Node\Expr\ArrayDimFetch);
                $key = $target->dim;
                if (!$key instanceof Node\Expr\Variable || !is_string($key->name) || !isset($params[$key->name])) {
                    return null;
                }

                // Запоминание результата фабрики: литеральной записи не касается
                if (!$callsEntry) {
                    $writers[$method->name->toLowerString()] = $params[$key->name];
                }
            }
        }

        // Делегирование: factory($id, ...) { $this->set($id, ...); }
        do {
            $grown = false;
            foreach ($class->getMethods() as $method) {
                foreach ($this->findNode(Node\Expr\MethodCall::class, $method->stmts ?? []) as $call) {
                    $name = $call->name instanceof Node\Identifier ? $call->name->toLowerString() : null;
                    if ($name === null || !isset($writers[$name]) || isset($writers[$method->name->toLowerString()])) {
                        continue;
                    }

                    $key = $call->args[$writers[$name]] ?? null;
                    foreach ($method->params as $i => $param) {
                        if (
                            $key instanceof Node\Arg && $key->value instanceof Node\Expr\Variable
                            && $param->var instanceof Node\Expr\Variable && $key->value->name === $param->var->name
                        ) {
                            $writers[$method->name->toLowerString()] = $i;
                            $grown = true;
                        }
                    }
                }
            }
        } while ($grown);

        return $writers;
    }

    /**
     * get($id): последний оператор — `return $this->P[$id]`, остальные — ветка вызова
     * фабрики, защищённая проверкой `instanceof \Closure` (прямо или через метод), и
     * проверки с throw (так get выглядит после встраивания resolved()).
     */
    private function getterMethod(Node\Stmt\Class_ $class, string $store): ?string
    {
        foreach ($class->getMethods() as $method) {
            $stmts = $method->stmts ?? [];
            $last  = end($stmts);
            if (!$last instanceof Node\Stmt\Return_ || !$last->expr instanceof Node\Expr || !$this->isStoreElement($last->expr, $store)) {
                continue;
            }

            $guarded = true;
            foreach (array_slice($stmts, 0, -1) as $stmt) {
                if (!$this->throwGuard($stmt) && (!$stmt instanceof Node\Stmt\If_ || !$this->closureGuard($stmt->cond, $class, $store))) {
                    $guarded = false;
                }
            }

            if ($guarded && count($stmts) > 0) {
                return $method->name->toLowerString();
            }
        }

        return null;
    }

    /** `if (…) { throw …; }` — проверка, которая значение не возвращает и записи не меняет. */
    private function throwGuard(Node\Stmt $stmt): bool
    {
        return $stmt instanceof Node\Stmt\If_ && $stmt->elseifs === [] && !$stmt->else instanceof Node\Stmt\Else_ && count($stmt->stmts) === 1
            && $stmt->stmts[0] instanceof Node\Stmt\Expression && $stmt->stmts[0]->expr instanceof Node\Expr\Throw_;
    }

    /** Условие истинно только для записи-замыкания: `!$this->resolved($id)`, `$this->P[$id] instanceof \Closure`. */
    private function closureGuard(Node\Expr $cond, Node\Stmt\Class_ $class, string $store): bool
    {
        // !!$this->P[$id] instanceof \Closure — след встроенного `!$this->resolved($id)`
        if ($cond instanceof Node\Expr\BooleanNot && $cond->expr instanceof Node\Expr\BooleanNot) {
            return $this->closureGuard($cond->expr->expr, $class, $store);
        }

        if ($cond instanceof Node\Expr\Instanceof_) {
            return $this->isStoreElement($cond->expr, $store) && $cond->class instanceof Node\Name
                && strtolower(ltrim($cond->class->toString(), '\\')) === 'closure';
        }

        if (
            $cond instanceof Node\Expr\BooleanNot && $cond->expr instanceof Node\Expr\MethodCall
            && $cond->expr->var instanceof Node\Expr\Variable && $cond->expr->var->name === 'this'
            && $cond->expr->name instanceof Node\Identifier
        ) {
            $method = $class->getMethod($cond->expr->name->toString());
            $stmts  = $method->stmts ?? [];
            $last   = end($stmts);

            // resolved(): return !$this->P[$id] instanceof \Closure; перед этим — только проверки с throw
            return $last instanceof Node\Stmt\Return_ && $last->expr instanceof Node\Expr\BooleanNot
                && $last->expr->expr instanceof Node\Expr\Instanceof_
                && $this->closureGuard($last->expr->expr, $class, $store)
                && array_all(array_slice($stmts, 0, -1), $this->throwGuard(...));
        }

        return false;
    }

    /**
     * Фабрики, сохранённые в контейнер на верхнем уровне (`$cnt->set('X', function ($c) {...})`):
     * контейнер вызывает их, передавая себя первым аргументом.
     *
     * @param array<string, int> $writers
     */
    private function collectFactories(string $var, array $writers): void
    {
        foreach ($this->ast as $stmt) {
            $call = $stmt instanceof Node\Stmt\Expression ? $stmt->expr : null;
            if (
                !$call instanceof Node\Expr\MethodCall || !$call->var instanceof Node\Expr\Variable || $call->var->name !== $var
                || !$call->name instanceof Node\Identifier || !isset($writers[$call->name->toLowerString()])
            ) {
                continue;
            }

            foreach ($call->args as $arg) {
                $first = $arg instanceof Node\Arg && ($arg->value instanceof Node\Expr\Closure || $arg->value instanceof Node\Expr\ArrowFunction)
                    ? $arg->value->params[0]->var ?? null
                    : null;
                if ($first instanceof Node\Expr\Variable && is_string($first->name) && $arg instanceof Node\Arg) {
                    $this->factories[$arg->value] = $first->name;
                }
            }
        }
    }

    /**
     * Ключи, которые могут быть записаны. Вызов «писателя» на получателе, который по
     * объявленным типам не может быть контейнером, не считается: PHP проверяет типы
     * свойств и параметров при выполнении. null — ключ вычисляется на получателе,
     * который может быть контейнером.
     *
     * @param  array<string, int> $writers
     * @return array<string, true>|null
     */
    private function writtenKeys(Node\Stmt\Class_ $class, array $writers): ?array
    {
        $written      = [];
        $writerNamed  = false;
        $strings      = [];
        foreach ($this->findNode(Node\Scalar\String_::class) as $string) {
            $strings[strtolower($string->value)] = true;
        }

        $minRequired = PHP_INT_MAX;
        foreach (array_keys($writers) as $writer) {
            $writerNamed = $writerNamed || isset($strings[$writer]);
            $minRequired = min($minRequired, $this->requiredParams($class, $writer));
        }

        foreach ($this->findNode(Node::class) as $node) {
            if (!$node instanceof Node\Expr\MethodCall && !$node instanceof Node\Expr\NullsafeMethodCall) {
                continue;
            }

            // Внутри класса контейнера писатели вызывают друг друга с параметром-ключом (делегирование учтено)
            if ($this->enclosingClass($node) === $class) {
                continue;
            }

            if (!$node->name instanceof Node\Identifier) {
                // $x->$m(...) с m = 'set'; вызов с меньшим числом аргументов писателем быть не может
                $names = $this->possibleStrings($node->name);
                $hits  = $names === null || array_intersect(array_map(strtolower(...), $names), array_keys($writers)) !== [];
                if (!$this->assumeImmutable && $writerNamed && $hits && !$this->tooFewArgs($node, $minRequired) && $this->mayBeContainer($node->var, $class)) {
                    return null;
                }

                continue;
            }

            $index = $writers[$node->name->toLowerString()] ?? null;
            if ($index === null || !$this->mayBeContainer($node->var, $class)) {
                continue;
            }

            // Аргументов меньше, чем обязательных параметров писателя: на контейнере это
            // ArgumentCountError, значит вызов — у другого объекта (`$item->set($value)`)
            if ($this->tooFewArgs($node, $this->requiredParams($class, $node->name->toString()))) {
                continue;
            }

            $key = $node->args[$index] ?? null;
            if (!$key instanceof Node\Arg || $key->unpack || !$key->value instanceof Node\Scalar\String_) {
                if ($this->assumeImmutable) {
                    continue;
                }

                return null;
            }

            $written[$key->value->value] = true;
        }

        return $written;
    }

    private function requiredParams(Node\Stmt\Class_ $class, string $method): int
    {
        $declaration = $class->getMethod($method);

        return $declaration instanceof Node\Stmt\ClassMethod
            ? count(array_filter($declaration->params, static fn(Node\Param $p): bool => !$p->default instanceof \PhpParser\Node\Expr && !$p->variadic))
            : 0;
    }

    private function tooFewArgs(Node\Expr\MethodCall|Node\Expr\NullsafeMethodCall $call, int $required): bool
    {
        $unpacks = array_any($call->args, static fn(Node $a): bool => !$a instanceof Node\Arg || $a->unpack);

        return !$unpacks && count($call->args) < $required;
    }

    /**
     * Может ли выражение оказаться экземпляром контейнера `$cnt` — анализ по запросу:
     * значение прослеживается назад до источников (присваивания, возвраты методов,
     * аргументы в местах вызова). `new X` — всегда новый объект, не `$cnt`; объявленный
     * (исходный) тип, не допускающий класс контейнера, сразу отсекает. Результаты
     * запоминаются; узел, который уже разбирается выше по рекурсии, новых источников не
     * добавляет и считается «нет».
     */
    private function mayBeContainer(Node\Expr $expr, Node\Stmt\Class_ $class, int $depth = 0): bool
    {
        $id = 'e' . spl_object_id($expr);
        if (array_key_exists($id, $this->memo)) {
            return $this->memo[$id];
        }

        if ($depth > 2000) {
            return true;
        }

        // Запрос верхнего уровня: промежуточные «нет» внутри цикла верны, только если
        // и итог — «нет»; при итоге «да» они снимаются из кэша
        $top = $this->active === 0;
        $this->active++;
        $mark = count($this->journal);
        $this->provisional($id);
        $result          = $this->computeMayBe($expr, $class, $depth);
        $this->memo[$id] = $result;
        $this->active--;
        if ($top) {
            if ($result) {
                foreach (array_slice($this->journal, $mark) as $key) {
                    if ($this->memo[$key] === false) {
                        unset($this->memo[$key]);
                    }
                }
            }

            $this->journal = [];
        }

        return $result;
    }

    private function provisional(string $key): void
    {
        $this->memo[$key] = false;
        $this->journal[]  = $key;
    }

    private function computeMayBe(Node\Expr $expr, Node\Stmt\Class_ $class, int $depth): bool
    {
        $next = $depth + 1;

        return match (true) {
            $expr instanceof Node\Expr\Variable => $this->variableMayBe($expr, $class, $next),
            $expr instanceof Node\Expr\New_, $expr instanceof Node\Scalar, $expr instanceof Node\Expr\Array_,
            $expr instanceof Node\Expr\ConstFetch, $expr instanceof Node\Expr\ClassConstFetch, $expr instanceof Node\Expr\Cast,
            $expr instanceof Node\Expr\BooleanNot, $expr instanceof Node\Expr\Isset_, $expr instanceof Node\Expr\Empty_,
            $expr instanceof Node\Expr\Instanceof_, $expr instanceof Node\Expr\Closure, $expr instanceof Node\Expr\ArrowFunction,
            $expr instanceof Node\Expr\UnaryMinus, $expr instanceof Node\Expr\UnaryPlus => false,
            $expr instanceof Node\Expr\BinaryOp\Coalesce => $this->mayBeContainer($expr->left, $class, $next) || $this->mayBeContainer($expr->right, $class, $next),
            $expr instanceof Node\Expr\BinaryOp => false,
            $expr instanceof Node\Expr\Ternary => ($expr->if instanceof Node\Expr ? $this->mayBeContainer($expr->if, $class, $next) : $this->mayBeContainer($expr->cond, $class, $next))
                || $this->mayBeContainer($expr->else, $class, $next),
            $expr instanceof Node\Expr\Match_ => array_any($expr->arms, fn(Node\MatchArm $arm): bool => $this->mayBeContainer($arm->body, $class, $next)),
            $expr instanceof Node\Expr\Assign, $expr instanceof Node\Expr\Clone_ => $this->mayBeContainer($expr->expr, $class, $next),
            $expr instanceof Node\Expr\AssignOp\Coalesce => $this->mayBeContainer($expr->var, $class, $next) || $this->mayBeContainer($expr->expr, $class, $next),
            $expr instanceof Node\Expr\AssignOp => false,
            $expr instanceof Node\Expr\PropertyFetch, $expr instanceof Node\Expr\NullsafePropertyFetch => $this->propertyMayBe($expr, $class, $next),
            $expr instanceof Node\Expr\ArrayDimFetch => $this->elementMayBe($expr, $class, $next),
            $expr instanceof Node\Expr\MethodCall, $expr instanceof Node\Expr\NullsafeMethodCall, $expr instanceof Node\Expr\StaticCall
                => $this->methodResultMayBe($expr, $class, $next),
            $expr instanceof Node\Expr\FuncCall => $this->functionResultMayBe($expr, $class, $next),
            $expr instanceof Node\Expr\StaticPropertyFetch => $this->staticPropertyMayBe($expr, $class, $next, false),
            default => true,
        };
    }

    private function variableMayBe(Node\Expr\Variable $var, Node\Stmt\Class_ $class, int $depth): bool
    {
        $function = $this->enclosingFunction($var);
        $key      = 'v' . ($function instanceof \PhpParser\Node\FunctionLike ? spl_object_id($function) : 0) . ':' . (is_string($var->name) ? $var->name : '?');
        if (is_string($var->name) && $var->name !== 'this') {
            if (array_key_exists($key, $this->memo)) {
                return $this->memo[$key];
            }

            $this->provisional($key);
            $result           = $this->computeVariableMayBe($var, $class, $depth);
            $this->memo[$key] = $result;

            return $result;
        }

        return $this->computeVariableMayBe($var, $class, $depth);
    }

    private function computeVariableMayBe(Node\Expr\Variable $var, Node\Stmt\Class_ $class, int $depth): bool
    {
        if (!is_string($var->name)) {
            return true;
        }

        if ($var->name === 'this') {
            return $this->enclosingClass($var) === $class;
        }

        $function = $this->enclosingFunction($var);
        $scope    = $function instanceof Node\FunctionLike ? $function->getStmts() ?? [] : $this->ast;
        if (!$function instanceof \PhpParser\Node\FunctionLike && $var->name === $this->topVar) {
            return true;
        }

        // Параметр: тип, места вызова
        foreach ($function instanceof Node\FunctionLike ? array_values($function->getParams()) : [] as $index => $param) {
            if ($param->var instanceof Node\Expr\Variable && $param->var->name === $var->name) {
                if (!$this->admits($this->declaredType($param), $class)) {
                    return false;
                }

                if ($function instanceof Node\FunctionLike && $this->paramMayBe($function, $index, $class, $depth)) {
                    return true;
                }
            }
        }

        // Захват замыкания: use ($x) — значение внешней переменной
        if ($function instanceof Node\Expr\Closure) {
            foreach ($function->uses as $use) {
                if ($use->var->name === $var->name && ($use->byRef || $this->mayBeContainer($use->var, $class, $depth))) {
                    return true;
                }
            }
        }

        foreach ($this->findNode(Node::class, $scope) as $node) {
            if ($this->enclosingFunction($node) !== $function) {
                continue;
            }

            if ($node instanceof Node\Expr\Assign && $node->var instanceof Node\Expr\Variable && $node->var->name === $var->name) {
                if ($this->mayBeContainer($node->expr, $class, $depth)) {
                    return true;
                }

                continue;
            }

            // foreach: ключ — строка или целое; значение — элемент перебираемого
            if ($node instanceof Node\Stmt\Foreach_ && $node->valueVar instanceof Node\Expr\Variable && $node->valueVar->name === $var->name) {
                if ($node->byRef || $this->mayBeContainer($node->expr, $class, $depth)) {
                    return true;
                }

                continue;
            }

            // [$a, $b] = $pair — элементы того, что разбирается
            if (
                $node instanceof Node\Expr\Assign && ($node->var instanceof Node\Expr\List_ || $node->var instanceof Node\Expr\Array_)
                && array_any($this->findNode(Node\Expr\Variable::class, $node->var), static fn(Node\Expr\Variable $v): bool => $v->name === $var->name)
            ) {
                if ($this->containsMayBe($node->expr, $class, $depth)) {
                    return true;
                }

                continue;
            }

            $other = match (true) {
                $node instanceof Node\Stmt\Foreach_ => $node->valueVar instanceof Node\Expr\Variable ? [] : [$node->valueVar],
                $node instanceof Node\Expr\AssignRef => [$node->var],
                $node instanceof Node\Stmt\Global_ => $node->vars,
                $node instanceof Node\Stmt\Static_ => array_map(static fn(Node\StaticVar $v): Node => $v->var, $node->vars),
                default => [],
            };
            if (array_any($other, static fn(\PhpParser\Node\Expr $v): bool => $v instanceof Node\Expr\Variable && $v->name === $var->name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Может ли вызов метода на получателе попасть в метод класса `$owner`: тип получателя —
     * сам класс, его предок (объект может быть наследником) или его наследник (метод унаследован).
     */
    private function mayDispatchTo(Node\Expr $receiver, Node\Stmt\Class_ $owner): bool
    {
        $classes = $this->receiverClasses($receiver);

        return $classes === null || array_any($classes, fn(string $name): bool => $this->relatesTo($name, $owner));
    }

    /** Может ли объект типа `$name` быть экземпляром `$owner` или его наследника (и наоборот). */
    private function relatesTo(string $name, Node\Stmt\Class_ $owner): bool
    {
        $declared = $this->classNamed($name);
        if ($declared instanceof Node\Stmt\Class_) {
            return $this->related($declared, $owner);
        }

        if ($declared instanceof Node\Stmt\Trait_ || $declared instanceof Node\Stmt\Enum_) {
            return $declared instanceof Node\Stmt\Trait_;
        }

        if (!$declared instanceof Node\Stmt\Interface_ && !class_exists($name) && !interface_exists($name)) {
            return true;
        }

        // Интерфейс или внешний тип: его реализует сам класс или какой-то наследник
        $this->classNamed('');
        return array_any($this->classes ?? [], fn(\PhpParser\Node\Stmt\ClassLike $candidate): bool => $candidate instanceof Node\Stmt\Class_ && $this->related($candidate, $owner) && $this->isAncestor($candidate, $name));
    }

    private function related(Node\Stmt\Class_ $a, Node\Stmt\Class_ $b): bool
    {
        return $a === $b || $this->isAncestor($a, strtolower((string) $b->name)) || $this->isAncestor($b, strtolower((string) $a->name));
    }

    private function classNamed(string $lower): ?Node\Stmt\ClassLike
    {
        if ($this->classes === null) {
            $this->classes = [];
            foreach ($this->findNode(Node\Stmt\ClassLike::class) as $declared) {
                if ($declared->name instanceof Node\Identifier) {
                    $this->classes[$declared->name->toLowerString()] = $declared;
                }
            }
        }

        return $this->classes[$lower] ?? null;
    }

    /**
     * Тип выражения как список имён классов (объединение) или null, если он неизвестен:
     * объявленные типы параметров, свойств и результатов методов, `new`, `clone`,
     * все присваивания локальной переменной, все записи `$x->prop` в связанных классах.
     *
     * @return list<string>|null
     */
    private function receiverClasses(Node\Expr $expr, int $depth = 0): ?array
    {
        $id = 't' . spl_object_id($expr);
        if (array_key_exists($id, $this->types)) {
            return $this->types[$id];
        }

        if ($depth > 12) {
            return null;
        }

        $this->types[$id] = null;
        $result           = $this->computeClasses($expr, $depth + 1);
        $this->types[$id] = $result === null ? null : array_values(array_unique($result));

        return $this->types[$id];
    }

    /** @return list<string>|null */
    private function computeClasses(Node\Expr $expr, int $depth): ?array
    {
        if ($expr instanceof Node\Expr\Variable && $expr->name === 'this') {
            $class = $this->enclosingClass($expr);

            return $class instanceof Node\Stmt\Class_ && !$this->enclosingFunction($expr) instanceof Node\Expr\Closure
                ? [strtolower((string) $class->name)]
                : null;
        }

        return match (true) {
            $expr instanceof Node\Expr\Variable && is_string($expr->name) => $this->variableClasses($expr, $depth),
            $expr instanceof Node\Expr\New_ => $expr->class instanceof Node\Name ? [strtolower(ltrim($expr->class->toString(), '\\'))] : null,
            $expr instanceof Node\Expr\Clone_, $expr instanceof Node\Expr\Assign => $this->receiverClasses($expr->expr, $depth),
            $expr instanceof Node\Expr\Ternary => $this->unionClasses([$expr->if ?? $expr->cond, $expr->else], $depth),
            $expr instanceof Node\Expr\BinaryOp\Coalesce => $this->unionClasses([$expr->left, $expr->right], $depth),
            $expr instanceof Node\Expr\PropertyFetch && $expr->name instanceof Node\Identifier => $this->propertyClasses($expr, $depth),
            ($expr instanceof Node\Expr\MethodCall || $expr instanceof Node\Expr\NullsafeMethodCall) && $expr->name instanceof Node\Identifier
                => $this->resultClasses($expr->name->toLowerString()),
            default => null,
        };
    }

    /**
     * @param list<Node\Expr> $exprs
     * @return list<string>|null
     */
    private function unionClasses(array $exprs, int $depth): ?array
    {
        $all = [];
        foreach ($exprs as $expr) {
            $classes = $this->receiverClasses($expr, $depth);
            if ($classes === null) {
                return null;
            }

            $all = [...$all, ...$classes];
        }

        return $all;
    }

    /** @return list<string>|null */
    private function typeClasses(?Node $type, ?Node\Stmt\ClassLike $context = null): ?array
    {
        return match (true) {
            $type instanceof Node\NullableType => $this->typeClasses($type->type, $context),
            $type instanceof Node\UnionType => $this->unionTypeClasses($type->types, $context),
            $type instanceof Node\Name && in_array($type->toLowerString(), ['self', 'static'], true)
                => $context instanceof Node\Stmt\ClassLike ? [strtolower((string) $context->name)] : null,
            $type instanceof Node\Name => [strtolower(ltrim($type->toString(), '\\'))],
            $type instanceof Node\Identifier && in_array($type->toLowerString(), ['self', 'static'], true)
                => $context instanceof Node\Stmt\ClassLike ? [strtolower((string) $context->name)] : null,
            // Скалярные типы: объектом не бывает — пустое объединение
            $type instanceof Node\Identifier && !in_array($type->toLowerString(), ['mixed', 'object', 'callable', 'iterable'], true) => [],
            default => null,
        };
    }

    /**
     * @param array<Node> $types
     * @return list<string>|null
     */
    private function unionTypeClasses(array $types, ?Node\Stmt\ClassLike $context): ?array
    {
        $all = [];
        foreach ($types as $type) {
            $classes = $type instanceof Node\IntersectionType ? null : $this->typeClasses($type, $context);
            if ($classes === null) {
                return null;
            }

            $all = [...$all, ...$classes];
        }

        return $all;
    }

    /** @return list<string>|null */
    private function variableClasses(Node\Expr\Variable $var, int $depth): ?array
    {
        $function = $this->enclosingFunction($var);
        $key      = 'v' . ($function instanceof \PhpParser\Node\FunctionLike ? spl_object_id($function) : 0) . ':' . (is_string($var->name) ? $var->name : '?');
        if (array_key_exists($key, $this->types)) {
            return $this->types[$key];
        }

        $this->types[$key] = null;
        $result            = $this->computeVariableClasses($var, $function, $depth);
        $this->types[$key] = $result;

        return $result;
    }

    /** @return list<string>|null */
    private function computeVariableClasses(Node\Expr\Variable $var, ?Node\FunctionLike $function, int $depth): ?array
    {
        $all = [];
        foreach ($function?->getParams() ?? [] as $param) {
            if ($param->var instanceof Node\Expr\Variable && $param->var->name === $var->name) {
                $classes = $param->variadic ? null : $this->typeClasses($this->declaredType($param), $this->enclosingClass($param));
                if ($classes === null) {
                    return null;
                }

                $all = $classes;
            }
        }

        $found = $all !== [];
        if ($function instanceof Node\Expr\Closure && array_any($function->uses, static fn(Node\ClosureUse $u): bool => $u->var->name === $var->name)) {
            return null;
        }

        foreach ($this->findNode(Node::class, $function instanceof Node\FunctionLike ? $function->getStmts() ?? [] : $this->ast) as $node) {
            if ($this->enclosingFunction($node) !== $function) {
                continue;
            }

            if ($node instanceof Node\Expr\Assign && $node->var instanceof Node\Expr\Variable && $node->var->name === $var->name) {
                $classes = $this->receiverClasses($node->expr, $depth);
                if ($classes === null) {
                    return null;
                }

                $all   = [...$all, ...$classes];
                $found = true;
                continue;
            }

            $binds = match (true) {
                $node instanceof Node\Stmt\Foreach_ => [$node->keyVar, $node->valueVar],
                $node instanceof Node\Expr\AssignRef, $node instanceof Node\Expr\AssignOp => [$node->var],
                $node instanceof Node\Expr\List_ => array_map(static fn(?Node\ArrayItem $i): ?Node => $i?->value, $node->items),
                $node instanceof Node\Stmt\Global_ => $node->vars,
                $node instanceof Node\Stmt\Static_ => array_map(static fn(Node\StaticVar $v): Node => $v->var, $node->vars),
                default => [],
            };
            if (array_any($binds, static fn(?Node $v): bool => $v instanceof Node\Expr\Variable && $v->name === $var->name)) {
                return null;
            }

            // Передача по ссылке в функцию (preg_match($p, $s, $m)) — значение неизвестно
            if ($node instanceof Node\Arg && $node->value instanceof Node\Expr\Variable && $node->value->name === $var->name && $node->byRef) {
                return null;
            }
        }

        return $found ? $all : null;
    }

    /** @return list<string>|null */
    private function propertyClasses(Node\Expr\PropertyFetch $fetch, int $depth): ?array
    {
        $name   = $fetch->name instanceof Node\Identifier ? $fetch->name->toString() : '';
        $owners = $this->receiverClasses($fetch->var, $depth);
        if ($owners === null || $owners === []) {
            return null;
        }

        $all = [];
        foreach ($owners as $ownerName) {
            $owner = $this->classNamed($ownerName);
            if (!$owner instanceof Node\Stmt\ClassLike) {
                return null;
            }

            $type    = $this->propertyType($owner, $name);
            $classes = $type === false ? null : $this->typeClasses($type, $owner);
            $classes ??= $this->writtenClasses($owner, $name, $depth);
            if ($classes === null) {
                return null;
            }

            $all = [...$all, ...$classes];
        }

        return $all;
    }

    /**
     * Тип свойства без объявленного типа — объединение всех записей в него у объектов
     * связанных классов; запись через выражение неизвестного типа — неизвестно.
     *
     * @return list<string>|null
     */
    private function writtenClasses(Node\Stmt\ClassLike $owner, string $property, int $depth): ?array
    {
        if (!$owner instanceof Node\Stmt\Class_) {
            return null;
        }

        $all = [];
        foreach ($this->index['propWrites'][$property] ?? [] as $write) {
            $target  = $write->var;
            $holders = $target instanceof Node\Expr\PropertyFetch ? $this->receiverClasses($target->var, $depth) : null;
            if ($holders === null) {
                return null;
            }

            $relevant = array_any($holders, function (string $holder) use ($owner): bool {
                $class = $this->classNamed($holder);

                return !$class instanceof Node\Stmt\Class_ || $this->related($class, $owner);
            });
            if (!$relevant) {
                continue;
            }

            $classes = $write instanceof Node\Expr\Assign ? $this->receiverClasses($write->expr, $depth) : null;
            if ($classes === null) {
                return null;
            }

            $all = [...$all, ...$classes];
        }

        return $all === [] ? null : $all;
    }

    /** @return list<string>|null объединение объявленных результатов всех одноимённых методов */
    private function resultClasses(string $method): ?array
    {
        $methods = $this->index['methods'][$method] ?? [];
        if ($methods === []) {
            return null;
        }

        $all = [];
        foreach ($methods as $declaration) {
            $classes = $this->typeClasses($this->declaredType($declaration), $this->enclosingClass($declaration));
            if ($classes === null) {
                return null;
            }

            $all = [...$all, ...$classes];
        }

        return $all;
    }

    /**
     * Все строки, которыми может оказаться выражение, или null, если набор неизвестен.
     *
     * @return list<string>|null
     */
    private function possibleStrings(Node\Expr $expr, int $depth = 0): ?array
    {
        if ($depth > 6) {
            return null;
        }

        if ($expr instanceof Node\Scalar\String_) {
            return [$expr->value];
        }

        if ($expr instanceof Node\Expr\Ternary || $expr instanceof Node\Expr\BinaryOp\Coalesce) {
            $parts = $expr instanceof Node\Expr\Ternary ? [$expr->if ?? $expr->cond, $expr->else] : [$expr->left, $expr->right];
            $all   = [];
            foreach ($parts as $part) {
                $strings = $this->possibleStrings($part, $depth + 1);
                if ($strings === null) {
                    return null;
                }

                $all = [...$all, ...$strings];
            }

            return $all;
        }

        if (!$expr instanceof Node\Expr\Variable || !is_string($expr->name) || $expr->name === 'this') {
            return null;
        }

        return $this->variableStrings($this->enclosingFunction($expr), $expr->name, $depth);
    }

    /**
     * Локальная переменная (не параметр): объединение всех присваиваний; захват
     * замыкания по значению — ещё и значения внешней переменной.
     *
     * @return list<string>|null
     */
    private function variableStrings(?Node\FunctionLike $function, string $name, int $depth): ?array
    {
        if (
            !$function instanceof Node\FunctionLike
            || array_any($function->getParams(), static fn(Node\Param $p): bool => $p->var instanceof Node\Expr\Variable && $p->var->name === $name)
        ) {
            return null;
        }

        $all = [];
        if ($function instanceof Node\Expr\Closure) {
            foreach ($function->uses as $use) {
                if ($use->var->name === $name) {
                    $outer = $use->byRef ? null : $this->variableStrings($this->enclosingFunction($function), $name, $depth + 1);
                    if ($outer === null) {
                        return null;
                    }

                    $all = $outer;
                }
            }
        }

        $expr = new Node\Expr\Variable($name);

        foreach ($this->findNode(Node::class, $function->getStmts() ?? []) as $node) {
            if ($this->enclosingFunction($node) !== $function) {
                continue;
            }

            if ($node instanceof Node\Expr\Assign && $node->var instanceof Node\Expr\Variable && $node->var->name === $expr->name) {
                if ($depth > 6) {
                    return null;
                }

                $strings = $this->possibleStrings($node->expr, $depth + 1);
                if ($strings === null) {
                    return null;
                }

                $all = [...$all, ...$strings];
                continue;
            }

            $binds = match (true) {
                $node instanceof Node\Stmt\Foreach_ => [$node->keyVar, $node->valueVar],
                $node instanceof Node\Expr\AssignRef, $node instanceof Node\Expr\AssignOp => [$node->var],
                $node instanceof Node\Expr\List_ => array_map(static fn(?Node\ArrayItem $i): ?Node => $i?->value, $node->items),
                $node instanceof Node\Stmt\Global_ => $node->vars,
                $node instanceof Node\Stmt\Static_ => array_map(static fn(Node\StaticVar $v): Node => $v->var, $node->vars),
                $node instanceof Node\Arg && $node->byRef => [$node->value],
                default => [],
            };
            if (array_any($binds, static fn(?Node $v): bool => $v instanceof Node\Expr\Variable && $v->name === $expr->name)) {
                return null;
            }

            // extract() и $$x могут писать в любую переменную (parse_str в PHP 8 — только во второй аргумент)
            if ($node instanceof Node\Expr\Variable && !is_string($node->name)) {
                return null;
            }

            if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name && $node->name->toLowerString() === 'extract') {
                return null;
            }
        }

        return $all === [] ? null : $all;
    }

    /**
     * Может ли контейнер оказаться внутри массива — элементом литерала, записью
     * `$v[...] = $x`, результатом неизвестного вызова.
     */
    private function containsMayBe(Node\Expr $expr, Node\Stmt\Class_ $class, int $depth): bool
    {
        $id = 'c' . spl_object_id($expr);
        if (array_key_exists($id, $this->memo)) {
            return $this->memo[$id];
        }

        if ($depth > 2000) {
            return true;
        }

        $this->provisional($id);
        $result          = match (true) {
            $expr instanceof Node\Expr\Array_ => array_any(
                $expr->items,
                fn(Node\ArrayItem $i): bool => $this->mayBeContainer($i->value, $class, $depth + 1)
                    || $this->containsMayBe($i->value, $class, $depth + 1),
            ),
            $expr instanceof Node\Scalar, $expr instanceof Node\Expr\ConstFetch, $expr instanceof Node\Expr\New_,
            $expr instanceof Node\Expr\ClassConstFetch, $expr instanceof Node\Expr\Isset_, $expr instanceof Node\Expr\Empty_,
            $expr instanceof Node\Expr\BooleanNot, $expr instanceof Node\Expr\Instanceof_,
            $expr instanceof Node\Expr\Cast && !$expr instanceof Node\Expr\Cast\Array_ && !$expr instanceof Node\Expr\Cast\Object_ => false,
            $expr instanceof Node\Expr\Variable && is_string($expr->name) => $this->localContainsMayBe($expr, $class, $depth + 1),
            $expr instanceof Node\Expr\Assign => $this->containsMayBe($expr->expr, $class, $depth + 1),
            $expr instanceof Node\Expr\Ternary => ($expr->if instanceof Node\Expr ? $this->containsMayBe($expr->if, $class, $depth + 1) : $this->containsMayBe($expr->cond, $class, $depth + 1))
                || $this->containsMayBe($expr->else, $class, $depth + 1),
            $expr instanceof Node\Expr\BinaryOp\Coalesce, $expr instanceof Node\Expr\BinaryOp\Plus
                => $this->containsMayBe($expr->left, $class, $depth + 1) || $this->containsMayBe($expr->right, $class, $depth + 1),
            $expr instanceof Node\Expr\BinaryOp => false,
            $expr instanceof Node\Expr\ArrayDimFetch => $this->elementMayBe($expr, $class, $depth + 1),
            $expr instanceof Node\Expr\PropertyFetch && $expr->name instanceof Node\Identifier
                => $this->propertyContentsMayBe($expr, $class, $depth + 1),
            $expr instanceof Node\Expr\FuncCall && $expr->name instanceof Node\Name
                && !in_array(strtolower(ltrim($expr->name->toString(), '\\')), ['func_get_args', 'func_get_arg'], true)
                => $this->functionContentsMayBe($expr, $class, $depth + 1),
            // func_get_args() — аргументы текущей функции
            $expr instanceof Node\Expr\FuncCall && $expr->name instanceof Node\Name
                && in_array(strtolower(ltrim($expr->name->toString(), '\\')), ['func_get_args', 'func_get_arg'], true)
                => $this->argsMayBe($expr, $class, $depth + 1),
            default => true,
        };
        $this->memo[$id] = $result;

        return $result;
    }

    private function argsMayBe(Node $at, Node\Stmt\Class_ $class, int $depth): bool
    {
        $function = $this->enclosingFunction($at);
        if (!$function instanceof Node\FunctionLike) {
            return true;
        }

        foreach (array_keys(array_values($function->getParams())) as $index) {
            if ($this->paramMayBe($function, $index, $class, $depth)) {
                return true;
            }
        }

        // Аргументы сверх объявленных параметров: любой вызов с лишними аргументами
        return !array_any($function->getParams(), static fn(Node\Param $p): bool => $p->variadic) && $this->extraArgsMayBe($function, $class, $depth);
    }

    private function extraArgsMayBe(Node\FunctionLike $function, Node\Stmt\Class_ $class, int $depth): bool
    {
        if (!$function instanceof Node\Stmt\ClassMethod && !$function instanceof Node\Stmt\Function_) {
            return true;
        }

        $name  = $function->name->toLowerString();
        $sites = $function instanceof Node\Stmt\Function_ ? $this->index['funcs'][$name] ?? [] : $this->index['calls'][$name] ?? [];
        $count = count($function->getParams());
        foreach ($sites as $call) {
            foreach (array_slice($call->getRawArgs(), $count) as $arg) {
                if ($arg instanceof Node\Arg && $this->mayBeContainer($arg->value, $class, $depth)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function localContainsMayBe(Node\Expr\Variable $var, Node\Stmt\Class_ $class, int $depth): bool
    {
        $function = $this->enclosingFunction($var);
        foreach ($function instanceof Node\FunctionLike ? array_values($function->getParams()) : [] as $index => $param) {
            if ($param->var instanceof Node\Expr\Variable && $param->var->name === $var->name) {
                // Массив пришёл параметром: содержимое аргументов в местах вызова
                if ($param->byRef || $function instanceof Node\FunctionLike && $this->paramMayBe($function, $index, $class, $depth, true)) {
                    return true;
                }
            }
        }

        if ($function instanceof Node\Expr\Closure && array_any($function->uses, static fn(Node\ClosureUse $u): bool => $u->var->name === $var->name)) {
            return true;
        }

        foreach ($this->findNode(Node::class, $function instanceof Node\FunctionLike ? $function->getStmts() ?? [] : $this->ast) as $node) {
            if ($this->enclosingFunction($node) !== $function || !$node instanceof Node\Expr) {
                continue;
            }

            $target = $node instanceof Node\Expr\Assign || $node instanceof Node\Expr\AssignRef || $node instanceof Node\Expr\AssignOp ? $node->var : null;
            $base   = $target;
            while ($base instanceof Node\Expr\ArrayDimFetch) {
                $base = $base->var;
            }

            if (!$base instanceof Node\Expr\Variable || $base->name !== $var->name) {
                continue;
            }

            if (!$node instanceof Node\Expr\Assign || $target !== $base && $this->mayBeContainer($node->expr, $class, $depth)) {
                return true;
            }

            if ($target === $base && $this->containsMayBe($node->expr, $class, $depth)) {
                return true;
            }
        }

        return false;
    }

    /** Аргумент в позиции параметра во всех местах вызова функции или метода. */
    /** $contains: не сам параметр, а что-то внутри него (параметр-массив) */
    private function paramMayBe(Node\FunctionLike $function, int $index, Node\Stmt\Class_ $class, int $depth, bool $contains = false): bool
    {
        $key = ($contains ? 'pc' : 'p') . spl_object_id($function) . ':' . $index;
        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        $this->provisional($key);
        $result           = $this->computeParamMayBe($function, $index, $class, $depth, $contains);
        $this->memo[$key] = $result;

        return $result;
    }

    private function computeParamMayBe(Node\FunctionLike $function, int $index, Node\Stmt\Class_ $class, int $depth, bool $contains): bool
    {
        if (isset($this->factories[$function])) {
            return $index === 0 && !$contains;
        }

        if (!$function instanceof Node\Stmt\ClassMethod && !$function instanceof Node\Stmt\Function_) {
            return true; // замыкание вызывается откуда угодно
        }

        $name = $function->name->toLowerString();
        if (isset($this->index['callables'][$name])) {
            return true; // [$obj, 'm'] / 'C::m' — вызов через callable, аргументы неизвестны
        }

        $param = $function->getParams()[$index];
        $sites = $function instanceof Node\Stmt\Function_
            ? $this->index['funcs'][$name] ?? []
            : [...$this->index['calls'][$name] ?? [], ...$this->index['dynamicCalls']];
        if ($function instanceof Node\Stmt\ClassMethod && $name === '__construct') {
            $owner = $this->enclosingClass($function);
            $sites = $owner instanceof Node\Stmt\Class_ && !$owner->name instanceof \PhpParser\Node\Identifier
                ? $this->index['anonNews'][spl_object_id($owner)] ?? []
                : [...$this->index['news'][strtolower((string) $owner?->name)] ?? [], ...$this->index['dynamicNews'], ...$this->index['calls']['__construct'] ?? []];
        }

        $owner = $function instanceof Node\Stmt\ClassMethod ? $this->enclosingClass($function) : null;
        foreach ($sites as $call) {
            // new $cls(): имя класса — одна из известных строк, и нашего среди них нет
            if ($call instanceof Node\Expr\New_ && $call->class instanceof Node\Expr && $owner instanceof Node\Stmt\Class_) {
                $classes = $this->possibleStrings($call->class);
                if ($classes !== null && !in_array(strtolower((string) $owner->name), array_map(static fn(string $c): string => strtolower(ltrim($c, '\\')), $classes), true)) {
                    continue;
                }
            }

            // $x->$m(): имя метода — одна из известных строк, и среди них нет нашего
            if (($call instanceof Node\Expr\MethodCall || $call instanceof Node\Expr\NullsafeMethodCall) && $call->name instanceof Node\Expr) {
                $names = $this->possibleStrings($call->name);
                if ($names !== null && !in_array($name, array_map(strtolower(...), $names), true)) {
                    continue;
                }
            }

            // Одноимённый метод другого класса: получатель по объявленному типу им быть не может
            if (
                $owner instanceof Node\Stmt\Class_ && ($call instanceof Node\Expr\MethodCall || $call instanceof Node\Expr\NullsafeMethodCall)
                && !$this->mayDispatchTo($call->var, $owner)
            ) {
                continue;
            }

            foreach ($call->getRawArgs() as $position => $arg) {
                if (!$arg instanceof Node\Arg) {
                    continue;
                }

                // ...$args: в параметр попадает элемент распакованного массива
                if ($arg->unpack) {
                    if ($position <= $index && $this->containsMayBe($arg->value, $class, $depth)) {
                        return true;
                    }

                    continue;
                }

                $matches = ($arg->name instanceof Node\Identifier ? $param->var instanceof Node\Expr\Variable && $arg->name->toString() === $param->var->name : $position === $index)
                    || $param->variadic && $position >= $index;
                $hit     = $contains
                    ? $this->containsMayBe($arg->value, $class, $depth) || $param->variadic && $this->mayBeContainer($arg->value, $class, $depth)
                    : $this->mayBeContainer($arg->value, $class, $depth);
                if ($matches && $hit) {
                    return true;
                }
            }
        }

        return false;
    }

    private function propertyMayBe(Node\Expr\PropertyFetch|Node\Expr\NullsafePropertyFetch $fetch, Node\Stmt\Class_ $class, int $depth): bool
    {
        if (!$fetch->name instanceof Node\Identifier) {
            return true;
        }

        $name  = $fetch->name->toString();
        $owner = $fetch->var instanceof Node\Expr\Variable && $fetch->var->name === 'this' ? $this->enclosingClass($fetch) : null;
        if ($owner instanceof Node\Stmt\ClassLike) {
            $type = $this->propertyType($owner, $name);
            if ($type !== false && !$this->admits($type, $class)) {
                return false;
            }
        }

        // Записи в свойство с этим именем у объектов связанных классов; promoted-параметр — аргументы конструктора
        $holders = $this->receiverClasses($fetch->var);
        foreach ($this->index['propWrites'][$name] ?? [] as $write) {
            $target = $write->var;
            if ($target instanceof Node\Expr\PropertyFetch && !$this->namesRelate($holders, $this->receiverClasses($target->var))) {
                continue;
            }

            if ($write instanceof Node\Expr\AssignRef || $this->mayBeContainer($write->expr, $class, $depth)) {
                return true;
            }
        }

        foreach ($this->index['promoted'][$name] ?? [] as [$ctor, $position]) {
            $ctorClass = $this->enclosingClass($ctor);
            $ctorNames = $ctorClass instanceof Node\Stmt\Class_ && $ctorClass->name instanceof \PhpParser\Node\Identifier ? [$ctorClass->name->toLowerString()] : null;
            if ($this->namesRelate($holders, $ctorNames) && $this->paramMayBe($ctor, $position, $class, $depth)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Могут ли объекты типов из двух списков оказаться одним объектом (null — тип неизвестен).
     *
     * @param list<string>|null $a
     * @param list<string>|null $b
     */
    private function namesRelate(?array $a, ?array $b): bool
    {
        if ($a === null || $b === null) {
            return true;
        }

        foreach ($a as $left) {
            foreach ($b as $right) {
                $leftClass  = $this->classNamed($left);
                $rightClass = $this->classNamed($right);
                $relates    = match (true) {
                    $leftClass instanceof Node\Stmt\Class_ => $this->relatesTo($right, $leftClass),
                    $rightClass instanceof Node\Stmt\Class_ => $this->relatesTo($left, $rightClass),
                    default => true,
                };
                if ($relates) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Элемент массива (на любой глубине) — что-то из содержимого базового массива. */
    private function elementMayBe(Node\Expr\ArrayDimFetch $fetch, Node\Stmt\Class_ $class, int $depth): bool
    {
        $base = $fetch->var;
        while ($base instanceof Node\Expr\ArrayDimFetch) {
            $base = $base->var;
        }

        return match (true) {
            $base instanceof Node\Expr\Variable && is_string($base->name) && $base->name !== 'this' => $this->containsMayBe($base, $class, $depth),
            $base instanceof Node\Expr\PropertyFetch && $base->name instanceof Node\Identifier => $this->propertyContentsMayBe($base, $class, $depth),
            $base instanceof Node\Expr\FuncCall || $base instanceof Node\Expr\Array_ => $this->containsMayBe($base, $class, $depth),
            $base instanceof Node\Expr\StaticPropertyFetch => $this->staticPropertyMayBe($base, $class, $depth, true),
            default => true,
        };
    }

    /** Содержимое свойства-массива: записи элементов и присваивания целиком у связанных объектов. */
    private function propertyContentsMayBe(Node\Expr\PropertyFetch $fetch, Node\Stmt\Class_ $class, int $depth): bool
    {
        $name    = $fetch->name instanceof Node\Identifier ? $fetch->name->toString() : '';
        $holders = $this->receiverClasses($fetch->var);
        $holds   = function (Node\Expr $target) use ($holders): bool {
            while ($target instanceof Node\Expr\ArrayDimFetch) {
                $target = $target->var;
            }

            return !$target instanceof Node\Expr\PropertyFetch || $this->namesRelate($holders, $this->receiverClasses($target->var));
        };

        foreach ($this->index['elementWrites'][$name] ?? [] as $write) {
            if (!$holds($write->var)) {
                continue;
            }

            if ($write instanceof Node\Expr\AssignRef || $this->mayBeContainer($write->expr, $class, $depth) || $this->containsMayBe($write->expr, $class, $depth)) {
                return true;
            }
        }

        foreach ($this->index['propWrites'][$name] ?? [] as $write) {
            if (!$holds($write->var)) {
                continue;
            }

            if ($write instanceof Node\Expr\AssignRef || $this->containsMayBe($write->expr, $class, $depth)) {
                return true;
            }
        }

        foreach ($this->index['promoted'][$name] ?? [] as [$ctor, $position]) {
            $ctorClass = $this->enclosingClass($ctor);
            $ctorNames = $ctorClass instanceof Node\Stmt\Class_ && $ctorClass->name instanceof \PhpParser\Node\Identifier ? [$ctorClass->name->toLowerString()] : null;
            if ($this->namesRelate($holders, $ctorNames) && $this->paramMayBe($ctor, $position, $class, $depth, true)) {
                return true;
            }
        }

        return false;
    }

    /** Содержимое результата функции: сквозные array-функции — содержимое аргументов, чистые — скаляры. */
    private function functionContentsMayBe(Node\Expr\FuncCall $call, Node\Stmt\Class_ $class, int $depth): bool
    {
        $name    = strtolower(ltrim($call->name instanceof Node\Name ? $call->name->toString() : '', '\\'));
        $through = [
            'array_merge', 'array_replace', 'array_values', 'array_filter', 'array_slice', 'array_reverse', 'array_unique',
            'array_combine', 'array_fill', 'array_fill_keys', 'array_pad', 'array_merge_recursive', 'array_replace_recursive',
            'array_diff_key', 'array_intersect_key', 'array_diff', 'array_splice', 'iterator_to_array', 'array_chunk',
        ];
        if (in_array($name, $through, true)) {
            return array_any($call->getRawArgs(), fn(Node $a): bool => !$a instanceof Node\Arg
                || $this->containsMayBe($a->value, $class, $depth) || $this->mayBeContainer($a->value, $class, $depth));
        }

        if (Sandbox::isPureFunction($name)) {
            return false;
        }

        if (($this->index['functions'][$name] ?? []) !== []) {
            return true;
        }

        return $this->externalResultMayBe($call, $name, $class, $depth);
    }

    /**
     * Внешняя функция (не из программы) видит объекты программы только через аргументы
     * и через результаты переданных ей замыканий; скалярный тип результата — точно нет.
     */
    private function externalResultMayBe(Node\Expr\FuncCall $call, string $name, Node\Stmt\Class_ $class, int $depth): bool
    {
        if (in_array($name, ['call_user_func', 'call_user_func_array', 'func_get_args', 'func_get_arg', 'get_defined_vars', 'compact'], true)) {
            return true;
        }

        if (function_exists($name)) {
            $type = new \ReflectionFunction($name)->getReturnType();
            $type ??= new \ReflectionFunction($name)->getTentativeReturnType();
            if ($type !== null && !preg_match('/mixed|object|array|iterable|callable|[A-Z]/', (string) $type)) {
                return false;
            }
        }

        foreach ($call->getRawArgs() as $arg) {
            if (!$arg instanceof Node\Arg) {
                return true;
            }

            $value = $arg->value;
            if ($value instanceof Node\Expr\Closure || $value instanceof Node\Expr\ArrowFunction) {
                foreach ($this->findNode(Node\Stmt\Return_::class, $value->getStmts()) as $return) {
                    if ($this->enclosingFunction($return) === $value && $return->expr instanceof Node\Expr && $this->mayBeContainer($return->expr, $class, $depth)) {
                        return true;
                    }
                }

                if ($value instanceof Node\Expr\ArrowFunction && $this->mayBeContainer($value->expr, $class, $depth)) {
                    return true;
                }

                continue;
            }

            if ($this->mayBeContainer($value, $class, $depth) || $this->containsMayBe($value, $class, $depth)) {
                return true;
            }
        }

        return false;
    }

    private function staticPropertyMayBe(Node\Expr\StaticPropertyFetch $fetch, Node\Stmt\Class_ $class, int $depth, bool $contents): bool
    {
        if (!$fetch->name instanceof Node\VarLikeIdentifier) {
            return true;
        }

        foreach ($this->index['staticWrites'][$fetch->name->toString()] ?? [] as $write) {
            if (!$write instanceof Node\Expr\Assign && !$write instanceof Node\Expr\AssignOp\Coalesce) {
                return true;
            }

            $whole = $write->var instanceof Node\Expr\StaticPropertyFetch;
            if (
                $whole && ($contents ? $this->containsMayBe($write->expr, $class, $depth) : $this->mayBeContainer($write->expr, $class, $depth))
                || !$whole && $contents && ($this->mayBeContainer($write->expr, $class, $depth) || $this->containsMayBe($write->expr, $class, $depth))
            ) {
                return true;
            }
        }

        return false;
    }

    private function methodResultMayBe(Node\Expr\MethodCall|Node\Expr\NullsafeMethodCall|Node\Expr\StaticCall $call, Node\Stmt\Class_ $class, int $depth): bool
    {
        if (!$call->name instanceof Node\Identifier) {
            return true;
        }

        $methods = $this->index['methods'][$call->name->toLowerString()] ?? [];
        if ($methods === []) {
            return true;
        }

        $holders = $call instanceof Node\Expr\StaticCall ? null : $this->receiverClasses($call->var);
        foreach ($methods as $method) {
            if (!$this->admits($this->declaredType($method), $class)) {
                continue;
            }

            $declaring = $this->enclosingClass($method);
            if ($declaring instanceof Node\Stmt\Class_ && $declaring->name instanceof \PhpParser\Node\Identifier && !$this->namesRelate($holders, [$declaring->name->toLowerString()])) {
                continue;
            }

            foreach ($this->findNode(Node\Stmt\Return_::class, $method->stmts ?? []) as $return) {
                if ($this->enclosingFunction($return) === $method && $return->expr instanceof Node\Expr && $this->mayBeContainer($return->expr, $class, $depth)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function functionResultMayBe(Node\Expr\FuncCall $call, Node\Stmt\Class_ $class, int $depth): bool
    {
        if (!$call->name instanceof Node\Name) {
            return true;
        }

        $name = strtolower(ltrim($call->name->toString(), '\\'));
        if (Sandbox::isPureFunction($name) && !in_array($name, ['current', 'end', 'reset', 'next', 'prev', 'array_pop', 'array_shift', 'array_search'], true)) {
            return false;
        }

        $functions = $this->index['functions'][$name] ?? [];
        if ($functions === []) {
            return $this->externalResultMayBe($call, $name, $class, $depth);
        }

        foreach ($functions as $function) {
            foreach ($this->findNode(Node\Stmt\Return_::class, $function->stmts) as $return) {
                if ($this->enclosingFunction($return) === $function && $return->expr instanceof Node\Expr && $this->mayBeContainer($return->expr, $class, $depth)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Индекс мест вызова, записей в свойства и строк — один обход на анализ. */
    private function buildIndex(): void
    {
        $index = [
            'calls' => [], 'dynamicCalls' => [], 'funcs' => [], 'news' => [], 'dynamicNews' => [], 'strings' => [],
            'methods' => [], 'propWrites' => [], 'elementWrites' => [], 'promoted' => [], 'callables' => [], 'functions' => [], 'anonNews' => [], 'staticWrites' => [],
        ];
        foreach ($this->findNode(Node::class) as $node) {
            if ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\NullsafeMethodCall || $node instanceof Node\Expr\StaticCall) {
                if ($node->name instanceof Node\Identifier) {
                    $index['calls'][$node->name->toLowerString()][] = $node;
                } else {
                    $index['dynamicCalls'][] = $node;
                }
            } elseif ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name) {
                $index['funcs'][strtolower(ltrim($node->name->toString(), '\\'))][] = $node;
            } elseif ($node instanceof Node\Expr\New_) {
                if ($node->class instanceof Node\Stmt\Class_) {
                    $index['anonNews'][spl_object_id($node->class)][] = $node;
                } elseif ($node->class instanceof Node\Name) {
                    $index['news'][strtolower(ltrim($node->class->toString(), '\\'))][] = $node;
                } else {
                    $index['dynamicNews'][] = $node;
                }
            } elseif ($node instanceof Node\Scalar\String_) {
                $index['strings'][strtolower($node->value)] = true;
                if (str_contains($node->value, '::')) {
                    $index['callables'][strtolower(substr($node->value, (int) strrpos($node->value, ':') + 1))] = true;
                }
            } elseif ($node instanceof Node\Expr\Array_ && count($node->items) === 2 && $node->items[1]?->value instanceof Node\Scalar\String_) {
                $index['callables'][strtolower($node->items[1]->value->value)] = true;
            } elseif ($node instanceof Node\Stmt\Function_) {
                $index['functions'][$node->name->toLowerString()][] = $node;
            } elseif ($node instanceof Node\Stmt\ClassMethod) {
                $index['methods'][$node->name->toLowerString()][] = $node;
                if ($node->name->toLowerString() === '__construct') {
                    foreach (array_values($node->params) as $position => $param) {
                        if ($param->flags !== 0 && $param->var instanceof Node\Expr\Variable && is_string($param->var->name)) {
                            $index['promoted'][$param->var->name][] = [$node, $position];
                        }
                    }
                }
            } elseif ($node instanceof Node\Expr\Assign || $node instanceof Node\Expr\AssignRef || $node instanceof Node\Expr\AssignOp\Coalesce) {
                $target = $node->var;
                $root = $target;
                while ($root instanceof Node\Expr\ArrayDimFetch) {
                    $root = $root->var;
                }

                if ($root instanceof Node\Expr\StaticPropertyFetch && $root->name instanceof Node\VarLikeIdentifier) {
                    $index['staticWrites'][$root->name->toString()][] = $node;
                }

                if ($target instanceof Node\Expr\PropertyFetch && $target->name instanceof Node\Identifier) {
                    $index['propWrites'][$target->name->toString()][] = $node;
                } elseif ($target instanceof Node\Expr\ArrayDimFetch) {
                    while ($target instanceof Node\Expr\ArrayDimFetch) {
                        $target = $target->var;
                    }

                    if ($target instanceof Node\Expr\PropertyFetch && $target->name instanceof Node\Identifier) {
                        $index['elementWrites'][$target->name->toString()][] = $node;
                    }
                }
            }
        }

        $this->index = $index;
    }

    /** Объявленный тип свойства (в том числе promoted) или false, если свойство не найдено. */
    private function propertyType(Node\Stmt\ClassLike $owner, string $name): Node|null|false
    {
        $property = $owner->getProperty($name);
        if ($property instanceof Node\Stmt\Property) {
            return $this->declaredType($property);
        }

        $ctor = $owner->getMethod('__construct');
        foreach ($ctor instanceof Node\Stmt\ClassMethod ? $ctor->params : [] as $param) {
            if ($param->flags !== 0 && $param->var instanceof Node\Expr\Variable && $param->var->name === $name) {
                return $this->declaredType($param);
            }
        }

        return false;
    }

    private function declaredType(Node\Param|Node\Stmt\Property|Node\Stmt\ClassMethod $node): ?Node
    {
        $attribute = $node instanceof Node\Stmt\ClassMethod ? 'originalReturnType' : 'originalType';
        $original  = $node->getAttribute($attribute);
        $current   = $node instanceof Node\Stmt\ClassMethod ? $node->returnType : $node->type;

        return $original instanceof Node ? $original : $current;
    }

    /** Допускает ли тип объект класса контейнера. */
    private function admits(?Node $type, Node\Stmt\Class_ $class): bool
    {
        if (!$type instanceof \PhpParser\Node) {
            return true;
        }

        if ($type instanceof Node\NullableType) {
            return $this->admits($type->type, $class);
        }

        if ($type instanceof Node\UnionType) {
            return array_any($type->types, fn(Node $t): bool => $this->admits($t, $class));
        }

        if ($type instanceof Node\IntersectionType) {
            return array_all($type->types, fn(Node $t): bool => $this->admits($t, $class));
        }

        if ($type instanceof Node\Identifier) {
            return in_array($type->toLowerString(), ['mixed', 'object', 'static', 'self'], true);
        }

        if (!$type instanceof Node\Name) {
            return true;
        }

        $name = strtolower(ltrim($type->toString(), '\\'));
        if ($name === strtolower((string) $class->name) || in_array($name, ['self', 'static', 'parent'], true)) {
            return true;
        }

        // Класс или интерфейс программы, которого нет среди предков контейнера, — не допускает
        if ($this->classNamed($name) instanceof Node\Stmt\ClassLike) {
            return $this->isAncestor($class, $name);
        }

        // Исходное имя класса программы (class_alias на плоское имя)
        $flat = str_replace('\\', '_', $name);
        if ($flat !== $name && $this->classNamed($flat) instanceof Node\Stmt\ClassLike) {
            return $this->isAncestor($class, $flat);
        }

        // Внешний класс (загруженный или нет): контейнер — его наследник, только если
        // это видно по цепочке предков (если все они известны)
        return !$this->ancestorsKnown($class) || $this->isAncestor($class, $name);
    }

    private function ancestorsKnown(Node\Stmt\ClassLike $class): bool
    {
        $parents = match (true) {
            $class instanceof Node\Stmt\Class_ => [$class->extends, ...$class->implements],
            $class instanceof Node\Stmt\Interface_ => $class->extends,
            default => [],
        };
        foreach ($parents as $parent) {
            if (!$parent instanceof Node\Name) {
                continue;
            }

            $lower    = strtolower(ltrim($parent->toString(), '\\'));
            $declared = $this->classNamed($lower);
            if ($declared instanceof Node\Stmt\ClassLike ? !$this->ancestorsKnown($declared) : !class_exists($lower) && !interface_exists($lower)) {
                return false;
            }
        }

        return true;
    }

    private function isAncestor(Node\Stmt\Class_ $class, string $name): bool
    {
        $key = 'a' . spl_object_id($class) . ':' . $name;

        return $this->ancestry[$key] ??= $this->computeIsAncestor($class, $name);
    }

    private function computeIsAncestor(Node\Stmt\Class_ $class, string $name): bool
    {
        $queue = [$class];
        $seen  = [];
        while ($queue !== []) {
            $current = array_pop($queue);
            $parents = match (true) {
                $current instanceof Node\Stmt\Class_ => [$current->extends, ...$current->implements],
                $current instanceof Node\Stmt\Interface_ => $current->extends,
                default => [],
            };
            foreach ($parents as $parent) {
                if (!$parent instanceof Node\Name) {
                    continue;
                }

                $lower = strtolower(ltrim($parent->toString(), '\\'));
                if ($lower === $name) {
                    return true;
                }

                if (isset($seen[$lower])) {
                    continue;
                }

                $seen[$lower] = true;
                $declared     = $this->classNamed($lower);
                if ($declared instanceof Node\Stmt\ClassLike) {
                    $queue[] = $declared;
                }

                if (class_exists($lower, false) || interface_exists($lower, false)) {
                    if (is_a($lower, $name, true)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function enclosingFunction(Node $node): ?Node\FunctionLike
    {
        for ($parent = $node->getAttribute('parent'); $parent instanceof Node; $parent = $parent->getAttribute('parent')) {
            if ($parent instanceof Node\FunctionLike) {
                return $parent;
            }
        }

        return null;
    }

    private function enclosingClass(Node $node): ?Node\Stmt\ClassLike
    {
        for ($parent = $node->getAttribute('parent'); $parent instanceof Node; $parent = $parent->getAttribute('parent')) {
            if ($parent instanceof Node\Stmt\ClassLike) {
                return $parent;
            }
        }

        return null;
    }

    private function isThisProp(Node\Expr $expr): bool
    {
        return $expr instanceof Node\Expr\PropertyFetch && $expr->var instanceof Node\Expr\Variable
            && $expr->var->name === 'this' && $expr->name instanceof Node\Identifier;
    }

    private function isStore(Node\Expr $expr, string $store): bool
    {
        return $this->isThisProp($expr) && $expr instanceof Node\Expr\PropertyFetch
            && $expr->name instanceof Node\Identifier && $expr->name->toString() === $store;
    }

    private function isStoreElement(Node\Expr $expr, string $store): bool
    {
        return $expr instanceof Node\Expr\ArrayDimFetch && $this->isStore($expr->var, $store);
    }

    private function isConstantValue(Node\Expr $expr): bool
    {
        return match (true) {
            $expr instanceof Node\Scalar, $expr instanceof Node\Expr\ConstFetch, $expr instanceof Node\Expr\ClassConstFetch => true,
            $expr instanceof Node\Expr\BinaryOp => $this->isConstantValue($expr->left) && $this->isConstantValue($expr->right),
            $expr instanceof Node\Expr\UnaryMinus => $this->isConstantValue($expr->expr),
            $expr instanceof Node\Expr\Array_ => array_all(
                $expr->items,
                fn(Node\ArrayItem $item): bool => !$item->unpack && !$item->byRef
                    && $this->isConstantValue($item->value) && (!$item->key instanceof Node\Expr || $this->isConstantValue($item->key)),
            ),
            default => false,
        };
    }
}
