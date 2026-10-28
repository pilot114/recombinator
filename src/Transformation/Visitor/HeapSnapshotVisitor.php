<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\PrettyPrinter\Standard;
use Recombinator\Analysis\PurityAnalyzer;
use Recombinator\Core\TargetEnvironment;
use Recombinator\Support\Sandbox;

/**
 * Снимок кучи: начало программы, которое не зависит от запроса и ничего не делает
 * снаружи (сборка контейнера, приложения, маршрутов), выполняется заранее, а его код
 * заменяется гидратацией — объекты создаются без конструкторов и получают значения
 * свойств из снимка:
 *
 *   $cnt = new Container([...]);  $cnt->set('app', static function ($c) {...});
 *   $app = AppFactory::create(null, $cnt);  $app->map(['GET'], '/users', 'ListUsers');
 *   $app->run();
 *   →
 *   $__snap0 = (new \ReflectionClass(\Container::class))->newInstanceWithoutConstructor();
 *   $__snap1 = static function ($c) {...};
 *   …
 *   \Closure::bind(function () use ($__snap1): void { $this->entries = ['app' => $__snap1, …]; }, $__snap0, \Container::class)();
 *   $cnt = $__snap0;  $app = $__snap2;
 *   $app->run();
 *
 * Граница находится выполнением: инструментированная копия программы выполняется по
 * инструкциям верхнего уровня (snapshot-runtime.php), перед каждым побочным эффектом —
 * перехватчик, который записывает снимок на начало текущей инструкции и завершает
 * процесс до эффекта. Эффекты: встроенные функции не из списка чистых, вывод,
 * суперглобальные массивы, include, `new` встроенных классов, вопросы о расширениях,
 * которых на сервере нет, ошибки и исключения, `__FILE__`/`__LINE__`.
 *
 * Инструкции до границы с разрешёнными эффектами настройки окружения (define,
 * class_alias, spl_autoload_register…) и объявления остаются на месте — выполняются и
 * при работе программы; остальные заменяются гидратацией перед границей.
 *
 * Снимок снимается один раз (гидратация помечена) и только для замкнутой программы, если
 * версия PHP и ОС анализатора совпадают с сервером, а в куче только объекты классов
 * программы, перечисления и замыкания из её кода.
 */
#[VisitorMeta('Снимок кучи: выполнение независимого от запроса начала программы заранее')]
class HeapSnapshotVisitor extends BaseVisitor
{
    /** Эффекты настройки окружения: инструкция с ними остаётся и выполняется снова при работе программы. */
    private const array ENVIRONMENT_EFFECTS = [
        'define', 'class_alias', 'spl_autoload_register', 'error_reporting', 'ini_set', 'date_default_timezone_set',
        'mb_internal_encoding', 'setlocale', 'set_error_handler', 'set_exception_handler',
    ];

    /** Встроенные классы, создание которых не эффект. */
    private const array PURE_CLASSES = ['arrayiterator', 'arrayobject', 'splobjectstorage', 'weakmap', 'splqueue', 'splstack', 'spldoublylinkedlist', 'stdclass', 'closure'];

    /** Вопросы о наличии: аргумент — имя, ответ зависит от сервера. */
    private const array QUERIES = [
        'function_exists' => 'function', 'class_exists' => 'class', 'interface_exists' => 'class', 'enum_exists' => 'class',
        'trait_exists' => 'class', 'extension_loaded' => 'extension', 'defined' => 'constant', 'constant' => 'constant',
    ];

    /** @var array<int, Node\Expr\Closure|Node\Expr\ArrowFunction> номер → замыкание исходного кода */
    private array $closures = [];

    private string $lastReason = '';

    /** Префикс временных переменных гидратации, не занятый в программе (повторный снимок). */
    private string $prefix = '__snap';

    public function __construct(
        private readonly TargetEnvironment $environment,
        private readonly ?string $projectDir,
        private readonly ?string $entry,
        private readonly string $cacheDir,
    ) {
    }

    /** Почему снимок не снят (для отладки и тестов). */
    public function lastReason(): string
    {
        return $this->lastReason;
    }

    #[\Override]
    public function beforeTraverse(array $nodes): ?array
    {
        parent::beforeTraverse($nodes);
        $this->closures = [];
        if (!$this->applicable($nodes)) {
            return null;
        }

        $this->numberClosures($nodes);
        $this->prefix = $this->freePrefix($nodes);
        $stopAt = null;
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $result = $this->run($nodes, $stopAt);
            if ($result === null) {
                return null;
            }

            $boundary = $result['boundary'];
            $conflict = $this->conflict($nodes, $boundary, $result['effects']);
            if ($conflict === null) {
                // Прогрев — отдельным выполнением, остановленным на границе
                if ($this->environment->warmup !== [] && $boundary >= 0) {
                    $reason = $this->lastReason;
                    $warmed = $this->run($nodes, $boundary, true);
                    if ($warmed !== null && $warmed['boundary'] === $boundary) {
                        $result = $warmed;
                    }

                    $this->lastReason = $reason . ' | ' . $this->lastReason;
                }

                return $this->rewrite($nodes, $boundary, $result['dump'], $result['effects']);
            }

            $stopAt = $conflict;
        }

        $this->lastReason = 'boundary did not settle';

        return null;
    }

    /** @param array<Node> $nodes */
    private function applicable(array $nodes): bool
    {
        if ($this->projectDir === null || $this->entry === null || $this->hasPendingIncludes()) {
            $this->lastReason = 'not a closed program';

            return false;
        }

        foreach ($nodes as $node) {
            // Гидратация с теми же параметрами снимка — уже снят; с другими (прогрев, env) — снимается заново
            if ($node instanceof Node\Stmt\Namespace_ || $node->getAttribute('snapshotHydration') === $this->signature()) {
                $this->lastReason = $node instanceof Node\Stmt\Namespace_ ? 'namespaced program' : 'already hydrated';

                return false;
            }
        }

        // Все названные классы уже в программе: иначе их подтянут позже, и снимок
        // снят бы с программы, которая ещё не совпадает с исполняемой
        $declared = [];
        foreach (new NodeFinder()->findInstanceOf($nodes, Node\Stmt\ClassLike::class) as $class) {
            if ($class->name instanceof Node\Identifier) {
                $declared[$class->name->toLowerString()] = true;
            }
        }

        foreach (
            new NodeFinder()->find($nodes, static fn(Node $n): bool => ($n instanceof Node\Expr\New_ || $n instanceof Node\Expr\StaticCall
            || $n instanceof Node\Expr\ClassConstFetch) && $n->class instanceof Node\Name) as $use
        ) {
            assert($use instanceof Node\Expr\New_ || $use instanceof Node\Expr\StaticCall || $use instanceof Node\Expr\ClassConstFetch);
            assert($use->class instanceof Node\Name);
            $name = strtolower(ltrim($use->class->toString(), '\\'));
            // Имя с пространством имён в замкнутой (плоской) программе — класс расширения
            if (
                !isset($declared[$name]) && !in_array($name, ['self', 'static', 'parent'], true) && !str_contains($name, '\\')
                && !class_exists($name) && !interface_exists($name) && !enum_exists($name)
            ) {
                $this->lastReason = 'program not closed: ' . $name;

                return false;
            }
        }

        $version = $this->environment->phpVersionId();
        $os      = $this->environment->os;
        if (
            $version === null || intdiv($version, 100) !== intdiv(\PHP_VERSION_ID, 100) || !$this->environment->extensionsKnown()
            || $os !== null && strcasecmp($os, \PHP_OS_FAMILY) !== 0
        ) {
            $this->lastReason = 'environment differs from the analyzer';

            return false;
        }

        return true;
    }

    /** @param array<Node> $nodes */
    private function freePrefix(array $nodes): string
    {
        $used = [];
        foreach (new NodeFinder()->findInstanceOf($nodes, Node\Expr\Variable::class) as $var) {
            if (is_string($var->name) && str_starts_with($var->name, '__snap')) {
                $used[] = $var->name;
            }
        }

        for ($generation = 0;; $generation++) {
            $prefix = $generation === 0 ? '__snap' : '__snap' . $generation . '_';
            if (!array_any($used, static fn(string $name): bool => str_starts_with($name, $prefix))) {
                return $prefix;
            }
        }
    }

    /** @param array<Node> $nodes */
    private function numberClosures(array $nodes): void
    {
        foreach (new NodeFinder()->find($nodes, static fn(Node $n): bool => $n instanceof Node\Expr\Closure || $n instanceof Node\Expr\ArrowFunction) as $closure) {
            assert($closure instanceof Node\Expr\Closure || $closure instanceof Node\Expr\ArrowFunction);
            if ($this->inConstantExpression($closure)) {
                continue;
            }

            $id                  = count($this->closures);
            $this->closures[$id] = $closure;
            $closure->setAttribute('snapshotClosure', $id);
        }
    }

    private function inConstantExpression(Node $node): bool
    {
        for ($parent = $node->getAttribute('parent'); $parent instanceof Node; $parent = $parent->getAttribute('parent')) {
            if (
                $parent instanceof Node\Param || $parent instanceof Node\PropertyItem || $parent instanceof Node\Const_
                || $parent instanceof Node\StaticVar || $parent instanceof Node\Attribute
            ) {
                return true;
            }

            if ($parent instanceof Node\FunctionLike || $parent instanceof Node\Stmt) {
                return false;
            }
        }

        return false;
    }

    /**
     * Выполнение инструментированной копии; null — снимок не получился.
     *
     * @param  array<Node> $nodes
     * @return array{boundary: int, effects: array<int, list<string>>, dump: array<string, mixed>}|null
     */
    private function run(array $nodes, ?int $stopAt, bool $warmup = false): ?array
    {
        $copy  = $this->instrument($nodes, $stopAt);
        $code  = new Standard()->prettyPrintFile($copy);
        $config = $this->config($nodes, $warmup);
        $key    = sha1($code . "\0" . $stopAt . "\0" . serialize($config) . "\0" . sha1_file($this->runtime()));
        $cache = $this->cacheDir . '/snapshot-' . $key . '.ser';
        $raw   = is_file($cache) ? (string) file_get_contents($cache) : null;
        if ($raw === null) {
            $dir    = dirname($this->projectDir . '/' . $this->entry);
            $script = $dir . '/__rcb_snapshot_' . getmypid() . '.php';
            $out    = tempnam(sys_get_temp_dir(), 'rcbsnap');
            if ($out === false || file_put_contents($script, $code) === false) {
                $this->lastReason = 'cannot write probe';

                return null;
            }

            try {
                $env = sprintf(
                    'RCB_SNAPSHOT_OUT=%s RCB_SNAPSHOT_CONFIG=%s REQUEST_METHOD=GET REQUEST_URI=/',
                    escapeshellarg($out),
                    escapeshellarg(base64_encode(serialize($config))),
                );
                exec('cd ' . escapeshellarg($dir) . ' && ' . $env . ' timeout 60 ' . escapeshellarg(PHP_BINARY) . ' -d memory_limit=1G -d xdebug.mode=off '
                    . escapeshellarg($script) . ' > /dev/null 2>&1');
                $raw = is_file($out) ? (string) file_get_contents($out) : '';
            } finally {
                @unlink($script);
                @unlink($out);
            }

            if (!is_dir($this->cacheDir)) {
                mkdir($this->cacheDir, 0777, true);
            }

            file_put_contents($cache, $raw);
        }

        $result = $raw === '' ? false : @unserialize($raw, ['allowed_classes' => false]);
        if (!is_array($result) || !is_int($result['boundary'] ?? null) || !is_array($result['dump'] ?? null)) {
            $this->lastReason = 'probe produced no snapshot';

            return null;
        }

        $this->lastReason = 'stopped by ' . (is_string($result['reason'] ?? null) ? $result['reason'] : '?');
        if (is_array($result['warmupFailures'] ?? null) && $result['warmupFailures'] !== []) {
            $this->lastReason .= '; warmup failed: ' . json_encode($result['warmupFailures'], JSON_UNESCAPED_UNICODE);
        }

        /** @var array{boundary: int, effects: array<int, list<string>>, dump: array<string, mixed>} $result */
        return $result;
    }

    /**
     * @param  array<Node> $nodes
     * @return array<string, mixed>
     */
    private function config(array $nodes, bool $warmup = false): array
    {
        $classes = [];
        foreach ($nodes as $node) {
            if ($node instanceof Node\Stmt\ClassLike && $node->name instanceof Node\Identifier) {
                $classes[] = $node->name->toLowerString();
            }
        }

        return [
            'allowed'     => self::ENVIRONMENT_EFFECTS,
            'pure'        => $this->pureFunctions(),
            'pureClasses' => self::PURE_CLASSES,
            'extensions'  => array_map(strtolower(...), array_keys($this->environment->extensions ?? [])),
            'classes'     => $classes,
            'warmup'      => $warmup ? $this->environment->warmup : [],
            'env'         => $this->environment->env,
            'known'       => array_filter(['sys_get_temp_dir' => $this->environment->ini['sys_temp_dir'] ?? null], is_string(...)),
        ];
    }

    /** @return list<string> */
    private function pureFunctions(): array
    {
        return array_values(array_filter(
            get_defined_functions()['internal'],
            // Вопросы о наличии детерминированы: выполняется вся программа (встроенное из чужих расширений ловит __rcb_q)
            static fn(string $f): bool => Sandbox::isPureFunction($f) || in_array($f, PurityAnalyzer::EXTRA_PURE, true)
                || in_array($f, PurityAnalyzer::EXISTENCE_QUERIES, true),
        ));
    }

    /**
     * Копия программы с перехватчиками эффектов и снимками перед инструкциями верхнего уровня.
     *
     * @param  array<Node> $nodes
     * @return array<Node>
     */
    private function instrument(array $nodes, ?int $stopAt): array
    {
        $pure      = array_flip($this->pureFunctions());
        $functions = [];
        foreach (new NodeFinder()->findInstanceOf($nodes, Node\Stmt\Function_::class) as $function) {
            $functions[$function->name->toLowerString()] = true;
        }

        $instrumenter = new class ($pure, $functions) extends NodeVisitorAbstract {
            /**
             * @param array<string, int>  $pure
             * @param array<string, true> $functions
             */
            public function __construct(private readonly array $pure, private readonly array $functions)
            {
            }

            public function leaveNode(Node $node): ?\PhpParser\Node
            {
                $guarded = $node->getAttribute('snapshotGuard');
                if (is_string($guarded)) {
                    $node->setAttribute('snapshotGuard', null);
                    if ($node instanceof Node\Stmt\Unset_) {
                        return new Node\Stmt\If_(new Node\Expr\BooleanNot(self::call('__rcb_fx', [new Node\Scalar\String_($guarded)])), ['stmts' => [$node]]);
                    }

                    if ($node instanceof Node\Expr) {
                        return $this->guard($guarded, $node);
                    }
                }

                // Переменная окружения с литеральным именем: значение из описания сервера
                if (
                    $node instanceof Node\Expr\ArrayDimFetch && $node->var instanceof Node\Expr\FuncCall && $node->var->getAttribute('snapshotSuperglobal') !== null
                    && $node->dim instanceof Node\Scalar\String_ && $node->getAttribute('snapshotEnvRead') === true
                ) {
                    return self::call('__rcb_env', [new Node\Scalar\String_($node->dim->value)]);
                }

                return match (true) {
                    $node instanceof Node\Expr\FuncCall => $this->funcCall($node),
                    $node instanceof Node\Expr\New_ => $this->newExpr($node),
                    $node instanceof Node\Expr\StaticCall && $node->class instanceof Node\Name && !$this->programClass($node->class)
                        && !in_array(strtolower(ltrim($node->class->toString(), '\\')), ['closure', 'self', 'static', 'parent'], true)
                        && !str_starts_with(strtolower(ltrim($node->class->toString(), '\\')), 'reflection')
                        => $this->guard('static ' . $node->class->toString(), $node),
                    $node instanceof Node\Expr\Include_, $node instanceof Node\Expr\Eval_, $node instanceof Node\Expr\ShellExec,
                    $node instanceof Node\Expr\Exit_, $node instanceof Node\Expr\Print_ => $this->guard($node->getType(), $node),
                    $node instanceof Node\Stmt\Echo_ => new Node\Stmt\Echo_([self::call('__rcb_fxs', [new Node\Scalar\String_('echo')]), ...$node->exprs], $node->getAttributes()),
                    $node instanceof Node\Stmt\InlineHTML => new Node\Stmt\Echo_([self::call('__rcb_fxs', [new Node\Scalar\String_('html')]), new Node\Scalar\String_($node->value)]),
                    $node instanceof Node\StaticVar => $this->staticVar($node),
                    $node instanceof Node\Expr\Variable && is_string($node->name) && in_array($node->name, ['GLOBALS', '_GET', '_POST', '_SERVER', '_COOKIE', '_FILES', '_ENV', '_REQUEST', '_SESSION'], true)
                        => $this->superglobal($node),
                    $node instanceof Node\Scalar\MagicConst\File, $node instanceof Node\Scalar\MagicConst\Line
                        => self::call('__rcb_fxv', [new Node\Scalar\String_('magic'), $node]),
                    ($node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction) && is_int($node->getAttribute('snapshotClosure'))
                        => self::call('__rcb_cl', [new Node\Scalar\Int_($node->getAttribute('snapshotClosure')), $node]),
                    default => null,
                };
            }

            private function funcCall(Node\Expr\FuncCall $call): ?Node
            {
                if (!$call->name instanceof Node\Name) {
                    $call->name = self::call('__rcb_cb', [$call->name]);

                    return $call;
                }

                $name = strtolower(ltrim($call->name->toString(), '\\'));
                if (isset($this->functions[$name]) || str_starts_with($name, '__rcb_')) {
                    return null;
                }

                $query = HeapSnapshotVisitor::query($name);
                if ($query !== null && isset($call->args[0]) && $call->args[0] instanceof Node\Arg) {
                    $call->args[0]->value = self::call('__rcb_q', [new Node\Scalar\String_($query), $call->args[0]->value]);
                }

                if (isset($this->pure[$name]) || $query !== null) {
                    foreach (HeapSnapshotVisitor::callbackPositions($name) as $position) {
                        $arg = $call->args[$position] ?? null;
                        if ($arg instanceof Node\Arg) {
                            $arg->value = self::call('__rcb_cb', [$arg->value]);
                        }
                    }

                    return null;
                }

                return $this->guard($name, $call);
            }

            private function newExpr(Node\Expr\New_ $new): ?Node
            {
                if ($new->class instanceof Node\Stmt\Class_) {
                    return null;
                }

                if (!$new->class instanceof Node\Name) {
                    $new->class = self::call('__rcb_class', [$new->class]);

                    return $new;
                }

                $class = strtolower(ltrim($new->class->toString(), '\\'));
                if ($this->programClass($new->class) || in_array($class, ['self', 'static', 'parent'], true) || HeapSnapshotVisitor::pureClass($class)) {
                    return null;
                }

                return $this->guard('new ' . $class, $new);
            }

            private function programClass(Node\Name $name): bool
            {
                return !class_exists($name->toString()) && !interface_exists($name->toString());
            }

            private function staticVar(Node\StaticVar $var): Node\StaticVar
            {
                $var->default = self::call('__rcb_fxv', [new Node\Scalar\String_('static variable'), $var->default ?? new Node\Expr\ConstFetch(new Node\Name('null'))]);

                return $var;
            }

            private function superglobal(Node\Expr\Variable $var): ?Node
            {
                // Запись в суперглобальный массив — эффект всей инструкции записи: такие места
                // перехватываются на уровне присваивания (см. ниже), чтение — здесь
                if ($var->getAttribute('snapshotWrite') === true) {
                    return null;
                }

                // $_SERVER['X'] / $_ENV['X'] (не в isset): перехват — по имени переменной
                $call = self::call($var->getAttribute('snapshotEnvRead') === true ? '__rcb_envarr' : '__rcb_fxv', [new Node\Scalar\String_('$' . $var->name), $var]);
                $call->setAttribute('snapshotSuperglobal', $var->name);

                return $call;
            }

            /** `(\__rcb_fx('what') ?: expr)`: перехватчик срабатывает до эффекта. */
            public function guard(string $what, Node\Expr $expr): \PhpParser\Node\Expr\Ternary
            {
                return new Node\Expr\Ternary(self::call('__rcb_fx', [new Node\Scalar\String_($what)]), null, $expr);
            }

            /** @param list<Node\Expr> $args */
            public static function call(string $name, array $args): Node\Expr\FuncCall
            {
                return new Node\Expr\FuncCall(new Node\Name\FullyQualified($name), array_map(static fn(Node\Expr $a): Node\Arg => new Node\Arg($a), $args));
            }
        };

        $copy = array_map($this->deepClone(...), $nodes);
        $copy = new NodeTraverser(new \PhpParser\NodeVisitor\ParentConnectingVisitor())->traverse($copy);
        $this->markSuperglobalWrites($copy);
        $copy = new NodeTraverser($instrumenter)->traverse($copy);

        $result = [new Node\Stmt\Expression(new Node\Expr\Include_(
            new Node\Scalar\String_($this->runtime()),
            Node\Expr\Include_::TYPE_REQUIRE,
        ))];
        foreach ($copy as $index => $stmt) {
            if ($this->executable($stmt)) {
                $result[] = new Node\Stmt\Expression($instrumenter::call('__rcb_stmt', [
                    new Node\Scalar\Int_((int) $index),
                    $instrumenter::call('get_defined_vars', []),
                    $stopAt === null ? new Node\Expr\ConstFetch(new Node\Name('null')) : new Node\Scalar\Int_($stopAt),
                ]));
            }

            $result[] = $stmt;
        }

        $result[] = new Node\Stmt\Expression($instrumenter::call('__rcb_stmt', [
            new Node\Scalar\Int_(count($copy)),
            $instrumenter::call('get_defined_vars', []),
            new Node\Expr\ConstFetch(new Node\Name('null')),
        ]));
        $result[] = new Node\Stmt\Expression($instrumenter::call('__rcb_end', []));

        return $result;
    }

    /**
     * Запись в суперглобальный массив (присваивание, unset, передача аргументом — может
     * быть по ссылке) перехватывается целиком: оборачивается вся операция.
     *
     * @param array<Node> $copy
     */
    private function markSuperglobalWrites(array $copy): void
    {
        $names = ['GLOBALS', '_GET', '_POST', '_SERVER', '_COOKIE', '_FILES', '_ENV', '_REQUEST', '_SESSION'];
        foreach (new NodeFinder()->find($copy, static fn(Node $n): bool => $n instanceof Node\Expr\Variable && in_array($n->name, $names, true)) as $var) {
            $child = $var;
            for ($parent = $var->getAttribute('parent'); $parent instanceof Node\Expr\ArrayDimFetch || $parent instanceof Node\Expr\PropertyFetch; $parent = $parent->getAttribute('parent')) {
                $child = $parent;
            }

            $fetch = $var->getAttribute('parent');
            if (
                in_array($var->name, ['_SERVER', '_ENV'], true) && $fetch instanceof Node\Expr\ArrayDimFetch && $fetch->var === $var
                && $fetch->dim instanceof Node\Scalar\String_ && !$fetch->getAttribute('parent') instanceof Node\Expr\Isset_
                && !$fetch->getAttribute('parent') instanceof Node\Expr\Empty_ && $parent === $fetch->getAttribute('parent')
                && !($parent instanceof Node\Expr\Assign || $parent instanceof Node\Expr\AssignOp || $parent instanceof Node\Expr\AssignRef
                    || $parent instanceof Node\Stmt\Unset_ || $parent instanceof Node\Arg)
            ) {
                $var->setAttribute('snapshotEnvRead', true);
                $fetch->setAttribute('snapshotEnvRead', true);
            }

            $target = match (true) {
                ($parent instanceof Node\Expr\Assign || $parent instanceof Node\Expr\AssignOp || $parent instanceof Node\Expr\AssignRef) && $parent->var === $child,
                $parent instanceof Node\Expr\PreInc, $parent instanceof Node\Expr\PreDec, $parent instanceof Node\Expr\PostInc, $parent instanceof Node\Expr\PostDec,
                $parent instanceof Node\Stmt\Unset_ => $parent,
                $parent instanceof Node\Arg => $parent->getAttribute('parent'),
                $parent instanceof Node\Stmt\Foreach_ && $parent->byRef => null,
                default => false,
            };
            if ($target === false) {
                continue;
            }

            assert(is_string($var->name));
            $var->setAttribute('snapshotWrite', true);
            if ($target instanceof Node) {
                $target->setAttribute('snapshotGuard', '$' . $var->name);
            }
        }
    }

    /** Параметры окружения, от которых зависит снимок. */
    private function signature(): string
    {
        return sha1(serialize([$this->environment->warmup, $this->environment->env, $this->environment->ini, $this->environment->extensions]));
    }

    private function runtime(): string
    {
        return dirname(__DIR__, 2) . '/Support/snapshot-runtime.php';
    }

    public static function query(string $function): ?string
    {
        return self::QUERIES[$function] ?? null;
    }

    /** @return list<int> */
    public static function callbackPositions(string $function): array
    {
        $position = PurityAnalyzer::CALLBACK_TAKERS[$function] ?? null;

        return $position === null ? [] : [$position];
    }

    public static function pureClass(string $class): bool
    {
        return in_array($class, self::PURE_CLASSES, true) || str_starts_with($class, 'reflection')
            || (class_exists($class) || interface_exists($class)) && is_a($class, \Throwable::class, true);
    }

    /** Инструкция верхнего уровня, которая что-то выполняет (не объявление). */
    private function executable(Node $node): bool
    {
        return !$node instanceof Node\Stmt\ClassLike && !$node instanceof Node\Stmt\Function_ && !$node instanceof Node\Stmt\Nop
            && !$node instanceof Node\Stmt\Const_ && !$node instanceof Node\Stmt\Declare_ && !$node instanceof Node\Stmt\HaltCompiler;
    }

    /** Остаётся на месте: объявляет что-то или настраивает окружение. */
    private function keeps(Node $node, array $effects): bool
    {
        return $effects !== [] || new NodeFinder()->findFirst($node, static fn(Node $n): bool => $n instanceof Node\Stmt\Function_ || $n instanceof Node\Stmt\ClassLike) instanceof \PhpParser\Node;
    }

    /**
     * Инструкция, которую нельзя ни оставить, ни заменить: остаётся на месте, но меняет
     * состояние программы или читает переменные, которые заменяет гидратация. Граница
     * снимка переносится на неё.
     *
     * @param array<Node>               $nodes
     * @param array<int, list<string>>  $effects
     */
    private function conflict(array $nodes, int $boundary, array $effects): ?int
    {
        foreach ($nodes as $index => $node) {
            if ($index >= $boundary) {
                break;
            }

            if (!$this->executable($node) || !$this->keeps($node, $effects[$index] ?? [])) {
                continue;
            }

            if (!$this->selfContained($node)) {
                return $index;
            }
        }

        return null;
    }

    /** Не трогает объекты программы и читает только свои переменные (тела замыканий и объявлений не в счёт). */
    private function selfContained(Node $node): bool
    {
        $assigned = [];
        $read     = [];
        $stack    = [$node];
        while ($stack !== []) {
            $current = array_pop($stack);
            if ($current instanceof Node\FunctionLike || $current instanceof Node\Stmt\ClassLike) {
                if ($current instanceof Node\Expr\Closure) {
                    foreach ($current->uses as $use) {
                        if (is_string($use->var->name)) {
                            $read[$use->var->name] = true;
                        }
                    }
                }

                continue;
            }

            if (
                $current instanceof Node\Expr\MethodCall || $current instanceof Node\Expr\NullsafeMethodCall || $current instanceof Node\Expr\StaticCall
                || $current instanceof Node\Expr\New_ && (!$current->class instanceof Node\Name || !self::pureClass(strtolower(ltrim($current->class->toString(), '\\'))))
                || $current instanceof Node\Expr\PropertyFetch && $this->isWritten($current) || $current instanceof Node\Expr\StaticPropertyFetch
                || $current instanceof Node\Expr\FuncCall && !$current->name instanceof Node\Name
                || $current instanceof Node\Expr\FuncCall && $current->name instanceof Node\Name && $this->programFunction($current->name)
            ) {
                return false;
            }

            if ($current instanceof Node\Expr\Assign && $current->var instanceof Node\Expr\Variable && is_string($current->var->name)) {
                $assigned[$current->var->name] = true;
            }

            if ($current instanceof Node\Stmt\Foreach_) {
                foreach ([$current->keyVar, $current->valueVar] as $var) {
                    if ($var instanceof Node\Expr\Variable && is_string($var->name)) {
                        $assigned[$var->name] = true;
                    }
                }
            }

            if ($current instanceof Node\Expr\Variable && is_string($current->name) && !$this->isAssignTarget($current)) {
                $read[$current->name] = true;
            }

            foreach ($current->getSubNodeNames() as $name) {
                $value = $current->$name;
                foreach (is_array($value) ? $value : [$value] as $child) {
                    if ($child instanceof Node) {
                        $stack[] = $child;
                    }
                }
            }
        }

        return array_diff_key($read, $assigned) === [];
    }

    private function isWritten(Node\Expr\PropertyFetch $fetch): bool
    {
        $parent = $fetch->getAttribute('parent');

        return ($parent instanceof Node\Expr\Assign || $parent instanceof Node\Expr\AssignOp || $parent instanceof Node\Expr\AssignRef) && $parent->var === $fetch;
    }

    private function isAssignTarget(Node\Expr\Variable $var): bool
    {
        $parent = $var->getAttribute('parent');

        return $parent instanceof Node\Expr\Assign && $parent->var === $var || $parent instanceof Node\Stmt\Foreach_;
    }

    private function programFunction(Node\Name $name): bool
    {
        return !function_exists($name->toString());
    }

    /**
     * Программа с гидратацией вместо заменяемых инструкций до границы.
     *
     * @param  array<Node>              $nodes
     * @param  array<string, mixed>     $dump
     * @param  array<int, list<string>> $effects
     * @return array<Node>|null
     */
    private function rewrite(array $nodes, int $boundary, array $dump, array $effects): ?array
    {
        $hydration = $this->hydration($dump);
        if ($hydration === null) {
            return null;
        }

        $removed = 0;
        $result  = [];
        foreach ($nodes as $index => $node) {
            if ($index === $boundary) {
                array_push($result, ...$hydration);
            }

            if ($index < $boundary && $this->executable($node) && !$this->keeps($node, $effects[$index] ?? [])) {
                $removed++;
                continue;
            }

            $result[] = $node;
        }

        if ($boundary >= count($nodes)) {
            array_push($result, ...$hydration);
        }

        if ($removed === 0) {
            $this->lastReason = 'nothing to replace';

            return null;
        }

        $this->lastReason = sprintf('hydrated %d statements before #%d (%s)', $removed, $boundary, $this->lastReason);

        return $result;
    }

    /**
     * Код, который воссоздаёт состояние снимка: объекты без конструкторов, замыкания из
     * исходного кода, свойства (в области видимости объявившего класса), статические
     * свойства, переменные верхнего уровня.
     *
     * @param  array<string, mixed> $dump
     * @return list<Node\Stmt>|null
     */
    private function hydration(array $dump): ?array
    {
        /** @var array<int, array<mixed>> $objects */
        $objects = is_array($dump['objects'] ?? null) ? $dump['objects'] : [];
        foreach ($objects as $object) {
            if (($object[0] ?? null) === 'unsupported') {
                $this->lastReason = 'unsupported value: ' . (is_string($object[1] ?? null) ? $object[1] : '?');

                return null;
            }
        }

        $stmts   = [];
        $closure = [];
        try {
            foreach ($objects as $id => $object) {
                if ($object[0] === 'object') {
                    assert(is_string($object[1]));
                    $stmts[] = $this->assign($this->snap($id), new Node\Expr\MethodCall(
                        new Node\Expr\New_(new Node\Name\FullyQualified('ReflectionClass'), [new Node\Arg($this->classConst($object[1]))]),
                        'newInstanceWithoutConstructor',
                    ));
                } else {
                    $closure[$id] = $object;
                }
            }

            // Замыкания: захваченные значения — объекты (уже созданы) или другие замыкания
            $emitted = [];
            while (count($emitted) < count($closure)) {
                $progress = false;
                foreach ($closure as $id => $object) {
                    if (isset($emitted[$id])) {
                        continue;
                    }

                    $refs = $this->references(array_values(array_filter([$object[2] ?? null, ...array_values($object[4] ?? [])])));
                    if (array_any(array_keys($refs), static fn(int $ref): bool => isset($closure[$ref]) && !isset($emitted[$ref]) && $ref !== $id)) {
                        continue;
                    }

                    $stmts[]       = $this->assign($this->snap($id), $this->closureExpr($object));
                    $emitted[$id]  = true;
                    $progress      = true;
                }

                if (!$progress) {
                    throw new \RuntimeException('closure cycle');
                }
            }

            foreach ($objects as $id => $object) {
                if ($object[0] !== 'object') {
                    continue;
                }

                $byClass = [];
                foreach (is_array($object[2] ?? null) ? $object[2] : [] as $prop) {
                    if (!is_array($prop) || !is_string($prop[0] ?? null) || !is_string($prop[1] ?? null)) {
                        throw new \RuntimeException('malformed property');
                    }

                    $byClass[$prop[0]][$prop[1]] = $prop[2] ?? null;
                }

                foreach ($byClass as $declaring => $props) {
                    $body = [];
                    foreach ($props as $name => $value) {
                        $fetch  = new Node\Expr\PropertyFetch(new Node\Expr\Variable('this'), $name);
                        $body[] = $value === ['unset']
                            ? new Node\Stmt\Unset_([$fetch])
                            : new Node\Stmt\Expression(new Node\Expr\Assign($fetch, $this->value($value)));
                    }

                    $stmts[] = $this->bound($body, $this->snap($id), $declaring, false);
                }
            }

            $statics = [];
            foreach (is_array($dump['statics'] ?? null) ? $dump['statics'] : [] as $static) {
                if (!is_array($static) || !is_string($static[0] ?? null) || !is_string($static[1] ?? null)) {
                    throw new \RuntimeException('malformed static');
                }

                $statics[$static[0]][] = new Node\Stmt\Expression(new Node\Expr\Assign(
                    new Node\Expr\StaticPropertyFetch(new Node\Name('self'), $static[1]),
                    $this->value($static[2] ?? null),
                ));
            }

            foreach ($statics as $class => $body) {
                $stmts[] = $this->bound($body, new Node\Expr\ConstFetch(new Node\Name('null')), $class, true);
            }

            foreach (is_array($dump['vars'] ?? null) ? $dump['vars'] : [] as $name => $value) {
                $stmts[] = $this->assign(new Node\Expr\Variable((string) $name), $this->value($value));
            }
        } catch (\RuntimeException $runtimeException) {
            $this->lastReason = 'hydration: ' . $runtimeException->getMessage();

            return null;
        }

        foreach ($stmts as $stmt) {
            $stmt->setAttribute('snapshotHydration', $this->signature());
        }

        return $stmts;
    }

    private function snap(int $id): Node\Expr\Variable
    {
        return new Node\Expr\Variable($this->prefix . $id);
    }

    private function classConst(string $class): Node\Expr\ClassConstFetch
    {
        return new Node\Expr\ClassConstFetch(new Node\Name\FullyQualified($class), 'class');
    }

    private function assign(Node\Expr $var, Node\Expr $value): Node\Stmt\Expression
    {
        return new Node\Stmt\Expression(new Node\Expr\Assign($var, $value));
    }

    /**
     * `\Closure::bind(function () use (…): void { … }, $объект, \Класс::class)();`
     *
     * @param list<Node\Stmt> $body
     */
    private function bound(array $body, Node\Expr $target, string $scope, bool $static): Node\Stmt\Expression
    {
        $uses = array_map(
            static fn(string $name): Node\ClosureUse => new Node\ClosureUse(new Node\Expr\Variable($name)),
            array_keys($this->variablesIn($body)),
        );
        $fn = new Node\Expr\Closure(['static' => $static, 'uses' => $uses, 'stmts' => $body, 'returnType' => new Node\Identifier('void')]);

        return new Node\Stmt\Expression(new Node\Expr\FuncCall(new Node\Expr\StaticCall(
            new Node\Name\FullyQualified('Closure'),
            'bind',
            [new Node\Arg($fn), new Node\Arg($target), new Node\Arg($this->classConst($scope))],
        )));
    }

    /**
     * @param  array<Node> $nodes
     * @return array<string, true> имена `$__snapN` в коде
     */
    private function variablesIn(array $nodes): array
    {
        $names = [];
        foreach (new NodeFinder()->findInstanceOf($nodes, Node\Expr\Variable::class) as $var) {
            if (is_string($var->name) && str_starts_with($var->name, $this->prefix)) {
                $names[$var->name] = true;
            }
        }

        return $names;
    }

    /**
     * Номера объектов, на которые ссылаются значения.
     *
     * @param  list<mixed>      $values
     * @return array<int, true>
     */
    private function references(array $values): array
    {
        $result = [];
        $stack  = $values;
        while ($stack !== []) {
            $value = array_pop($stack);
            if (!is_array($value)) {
                continue;
            }

            if (($value[0] ?? null) === 'o' && is_int($value[1] ?? null)) {
                $result[$value[1]] = true;
            } elseif (($value[0] ?? null) === 'a' && is_array($value[1] ?? null)) {
                foreach ($value[1] as $pair) {
                    $stack[] = is_array($pair) ? $pair[1] ?? null : null;
                }
            }
        }

        return $result;
    }

    /**
     * @param  array<mixed> $object
     * @throws \RuntimeException
     */
    private function closureExpr(array $object): Node\Expr
    {
        [$kind, $source, $this_, $scope, $used] = $object + [null, null, null, null, []];
        assert(is_array($used));
        $scope = is_string($scope) ? $scope : null;
        if ($kind === 'fcc') {
            assert(is_string($source));
            if ($this_ !== null) {
                $inner = new Node\Expr\ArrowFunction(['expr' => new Node\Expr\MethodCall(new Node\Expr\Variable('this'), $source, [new Node\VariadicPlaceholder()])]);

                return $this->bindCall($inner, $this->value($this_), $scope);
            }

            if ($scope !== null) {
                $inner = new Node\Expr\ArrowFunction(['static' => true, 'expr' => new Node\Expr\StaticCall(new Node\Name('self'), $source, [new Node\VariadicPlaceholder()])]);

                return $this->bindCall($inner, new Node\Expr\ConstFetch(new Node\Name('null')), $scope);
            }

            return new Node\Expr\FuncCall(new Node\Name\FullyQualified($source), [new Node\VariadicPlaceholder()]);
        }

        $original = is_int($source) ? $this->closures[$source] ?? null : null;
        if ($original === null) {
            throw new \RuntimeException('unknown closure');
        }

        $copy = $this->deepClone($original);
        assert($copy instanceof Node\Expr\Closure || $copy instanceof Node\Expr\ArrowFunction);
        $copy->setAttribute('snapshotClosure', null);
        if ($copy instanceof Node\Expr\Closure && array_any($copy->uses, static fn(Node\ClosureUse $u): bool => $u->byRef)) {
            throw new \RuntimeException('closure captures by reference');
        }

        // Захваченные значения — параметры обёртки, из которой замыкание их и захватит
        $expr = $copy;
        if ($used !== []) {
            $expr = new Node\Expr\FuncCall(
                new Node\Expr\ArrowFunction([
                    'static' => true,
                    'params' => array_map(static fn(string $name): Node\Param => new Node\Param(new Node\Expr\Variable($name)), array_map(strval(...), array_keys($used))),
                    'expr'   => $copy,
                ]),
                array_map(fn(mixed $value): Node\Arg => new Node\Arg($this->value($value)), array_values($used)),
            );
        }

        if ($this_ !== null) {
            if ($copy->static) {
                throw new \RuntimeException('static closure with $this');
            }

            return new Node\Expr\StaticCall(new Node\Name\FullyQualified('Closure'), 'bind', [
                new Node\Arg($expr), new Node\Arg($this->value($this_)), new Node\Arg($scope === null ? new Node\Expr\ConstFetch(new Node\Name('null')) : $this->classConst($scope)),
            ]);
        }

        return $scope === null ? $expr : new Node\Expr\StaticCall(new Node\Name\FullyQualified('Closure'), 'bind', [
            new Node\Arg($expr), new Node\Arg(new Node\Expr\ConstFetch(new Node\Name('null'))), new Node\Arg($this->classConst($scope)),
        ]);
    }

    /** `\Closure::bind(fn() => …, $this, Scope::class)()` */
    private function bindCall(Node\Expr\ArrowFunction $inner, Node\Expr $this_, ?string $scope): Node\Expr
    {
        return new Node\Expr\FuncCall(new Node\Expr\StaticCall(new Node\Name\FullyQualified('Closure'), 'bind', [
            new Node\Arg($inner), new Node\Arg($this_), new Node\Arg($scope === null ? new Node\Expr\ConstFetch(new Node\Name('null')) : $this->classConst($scope)),
        ]));
    }

    /** @throws \RuntimeException */
    private function item(mixed $pair): Node\ArrayItem
    {
        if (!is_array($pair) || !is_int($pair[0] ?? null) && !is_string($pair[0] ?? null)) {
            throw new \RuntimeException('malformed array');
        }

        return new Node\ArrayItem($this->value($pair[1] ?? null), is_int($pair[0]) ? new Node\Scalar\Int_($pair[0]) : new Node\Scalar\String_($pair[0]));
    }

    /**
     * Значение снимка → выражение.
     *
     * @throws \RuntimeException
     */
    private function value(mixed $value): Node\Expr
    {
        if (!is_array($value)) {
            throw new \RuntimeException('malformed value');
        }

        return match ($value[0] ?? null) {
            's' => $this->scalar($value[1] ?? null),
            'a' => new Node\Expr\Array_(array_map($this->item(...), is_array($value[1] ?? null) ? array_values($value[1]) : [])),
            'o' => is_int($value[1] ?? null) ? $this->snap($value[1]) : throw new \RuntimeException('malformed reference'),
            'e' => is_string($value[1] ?? null) && is_string($value[2] ?? null)
                ? new Node\Expr\ClassConstFetch(new Node\Name\FullyQualified($value[1]), $value[2])
                : throw new \RuntimeException('malformed enum'),
            default => throw new \RuntimeException('unsupported value ' . (is_string($value[1] ?? null) ? $value[1] : '')),
        };
    }

    /** @throws \RuntimeException */
    private function scalar(mixed $value): Node\Expr
    {
        return match (true) {
            $value === null => new Node\Expr\ConstFetch(new Node\Name('null')),
            is_bool($value) => new Node\Expr\ConstFetch(new Node\Name($value ? 'true' : 'false')),
            is_int($value) => new Node\Scalar\Int_($value),
            is_float($value) => new Node\Scalar\Float_($value),
            is_string($value) => new Node\Scalar\String_($value),
            default => throw new \RuntimeException('malformed scalar'),
        };
    }
}
