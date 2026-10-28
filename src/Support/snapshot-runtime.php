<?php

/**
 * Среда выполнения снимка кучи (подключается в начало инструментированной копии программы,
 * см. HeapSnapshotVisitor).
 *
 * Перед каждой инструкцией верхнего уровня `__rcb_stmt()` кодирует состояние: переменные
 * верхнего уровня, статические свойства классов программы и все достижимые объекты.
 * Перед каждым побочным эффектом стоит `__rcb_fx()`: разрешённые эффекты настройки
 * окружения (define, class_alias, spl_autoload_register…) выполняются и запоминаются,
 * любой другой — до своего выполнения — записывает снимок на начало текущей инструкции
 * (граница) и завершает процесс. Эффект при снятии снимка не выполняется никогда.
 *
 * Результат (serialize) — в файл из переменной окружения RCB_SNAPSHOT_OUT:
 *   boundary — номер инструкции верхнего уровня, на которой снимок остановился;
 *   effects  — номер инструкции → разрешённые эффекты, выполненные в ней;
 *   reason   — что остановило снимок;
 *   vars     — имя → значение; statics — [класс, свойство, значение];
 *   objects  — номер → описание объекта (`object`, `closure`, `fcc`) или `unsupported`.
 *
 * Значение: ['s', скаляр] | ['a', [[ключ, значение], …]] | ['o', номер] | ['e', класс, случай]
 * | ['x', причина].
 */

declare(strict_types=1);

function __rcb_state(): stdClass
{
    static $state = null;
    if ($state === null) {
        $state              = new stdClass();
        $state->stmt        = -1;
        $state->dump        = null;
        $state->effects     = [];
        $state->done        = false;
        $state->warming     = null; // номер выполняемого прогревающего вызова
        $state->closures    = new WeakMap();
        $state->closureLines = [];
        $state->config      = unserialize((string) base64_decode((string) getenv('RCB_SNAPSHOT_CONFIG'), true));
        $state->out         = (string) getenv('RCB_SNAPSHOT_OUT');
    }

    return $state;
}

/** Эффект: разрешённый выполняется, остальные — граница снимка. Возвращает false. */
function __rcb_fx(string $what): bool
{
    $state = __rcb_state();
    if ($state->done) {
        __rcb_kill();
    }

    // Известное значение окружения: sys_get_temp_dir() совпадает с объявленным для сервера
    $known = $state->config['known'][$what] ?? null;
    if ($known !== null && $known === __rcb_actual($what)) {
        return false;
    }

    if ($state->warming !== null) {
        // Прогрев: эффект — вызов отбрасывается (процесс-форк завершается без результата)
        file_put_contents($state->out . '.fail' . $state->warming, $what);
        __rcb_trace('warmup ' . $state->warming . ' failed: ' . $what);
        __rcb_kill();
    }

    if (in_array($what, $state->config['allowed'], true)) {
        $state->effects[$state->stmt][$what] = true;

        return false;
    }

    __rcb_finish($what);
    __rcb_kill();
}

function __rcb_actual(string $what): ?string
{
    return match ($what) {
        'sys_get_temp_dir' => sys_get_temp_dir(),
        default => null,
    };
}

/**
 * `$_SERVER` / `$_ENV` перед чтением одного литерального ключа: сам массив не эффект,
 * эффект — чтение ключа, о котором описание сервера ничего не говорит (см. __rcb_env).
 *
 * @param  array<mixed> $value
 * @return array<mixed>
 */
function __rcb_envarr(string $what, array $value): array
{
    return $value;
}

/**
 * Переменная окружения с литеральным именем: описание сервера объявляет её значение
 * (null — переменной нет) — при снимке используется оно; иначе — эффект.
 */
function __rcb_env(string $name): mixed
{
    $env = __rcb_state()->config['env'];
    if (!array_key_exists($name, $env)) {
        __rcb_fx('env ' . $name);
    }

    return $env[$name];
}

/** Значение-эффект: `__rcb_fxv('_SERVER', $_SERVER)`. */
function __rcb_fxv(string $what, mixed $value): mixed
{
    __rcb_fx($what);

    return $value;
}

/** Вывод: `echo __rcb_fxs('echo'), …`. */
function __rcb_fxs(string $what): string
{
    __rcb_fx($what);

    return '';
}

/** Вызываемое значение динамического вызова: встроенная функция не из чистых — эффект. */
function __rcb_cb(mixed $callable): mixed
{
    $name = is_string($callable) ? $callable : (is_array($callable) && is_string($callable[0] ?? null) ? $callable[0] . '::' . ($callable[1] ?? '') : null);
    if (is_string($name) && !str_contains($name, '::') && function_exists($name)) {
        $function = new ReflectionFunction($name);
        if ($function->isInternal() && !in_array(strtolower($name), __rcb_state()->config['pure'], true)) {
            __rcb_fx($name);
        }
    } elseif (is_string($name) && str_contains($name, '::')) {
        __rcb_class(explode('::', $name, 2)[0]);
    } elseif (is_array($callable) && is_object($callable[0] ?? null)) {
        __rcb_class($callable[0]::class);
    }

    return $callable;
}

/** Класс динамического `new` / статического вызова: встроенный не из чистых — эффект. */
function __rcb_class(mixed $class): mixed
{
    if (is_string($class) && class_exists($class) && new ReflectionClass($class)->isInternal()) {
        $lower = strtolower(ltrim($class, '\\'));
        if (!in_array($lower, __rcb_state()->config['pureClasses'], true) && !is_a($class, Throwable::class, true) && !str_starts_with($lower, 'reflection')) {
            __rcb_fx('new ' . $lower);
        }
    }

    return $class;
}

/** Вопрос о наличии функции, класса, константы, расширения: ответ сервера может отличаться. */
function __rcb_q(string $kind, mixed $name): mixed
{
    if (!is_string($name)) {
        return $name;
    }

    $extensions = __rcb_state()->config['extensions'];
    $extension  = null;
    try {
        $extension = match ($kind) {
            'function' => function_exists($name) && new ReflectionFunction($name)->isInternal() ? new ReflectionFunction($name)->getExtensionName() : null,
            'class' => (class_exists($name, false) || interface_exists($name, false) || enum_exists($name, false))
                && new ReflectionClass($name)->isInternal() ? new ReflectionClass($name)->getExtensionName() : null,
            'extension' => $name,
            'constant' => __rcb_constant_extension($name),
            default => null,
        };
    } catch (Throwable) {
        __rcb_fx('query ' . $name);
    }

    if (is_string($extension) && !in_array(strtolower($extension), $extensions, true)) {
        __rcb_fx('query ' . $name);
    }

    // Не встроенное и не объявленное — нет и на сервере, если расширение со своим именем
    // есть здесь, но не на сервере
    if ($kind === 'extension' && extension_loaded($name) !== in_array(strtolower($name), $extensions, true)) {
        __rcb_fx('query ' . $name);
    }

    return $name;
}

function __rcb_constant_extension(string $name): ?string
{
    foreach (get_defined_constants(true) as $extension => $constants) {
        if ($extension !== 'user' && array_key_exists($name, $constants)) {
            return $extension;
        }
    }

    return null;
}

/**
 * Замыкание из исходного кода программы: номер — место в дереве.
 *
 * @param  Closure(mixed ...): mixed $closure
 * @return Closure(mixed ...): mixed
 */
function __rcb_cl(int $id, Closure $closure): Closure
{
    $state                    = __rcb_state();
    $state->closures[$closure] = $id;
    // Копии (bindTo, Closure::bind) — другие объекты того же кода: узнаются по строкам
    $function                            = new ReflectionFunction($closure);
    $lines                               = $function->getStartLine() . ':' . $function->getEndLine();
    $state->closureLines[$lines][$id]    = true;

    return $closure;
}

/**
 * Начало инструкции верхнего уровня: снимок состояния на этот момент.
 *
 * @param array<string, mixed> $vars
 */
function __rcb_stmt(int $index, array $vars, ?int $stopAt): void
{
    $state = __rcb_state();
    if ($state->done) {
        __rcb_kill();
    }

    $state->stmt = $index;
    unset($vars['GLOBALS'], $vars['_GET'], $vars['_POST'], $vars['_COOKIE'], $vars['_FILES'], $vars['_SERVER'], $vars['_ENV'], $vars['_REQUEST'], $vars['_SESSION'], $vars['argv'], $vars['argc']);
    $encoder = new __RcbSnapshotEncoder($state->config['classes']);
    $encoded = [];
    foreach ($vars as $name => $value) {
        $encoded[$name] = $encoder->encode($value);
    }

    $statics     = $encoder->statics();
    $state->dump = ['vars' => $encoded, 'statics' => $statics, 'objects' => $encoder->objects];
    if ($index === $stopAt) {
        if ($state->config['warmup'] !== []) {
            __rcb_warm($vars, 0);
        }

        __rcb_finish('forced');
        __rcb_kill();
    }
}

/**
 * Объекты класса (и наследников), достижимые из переменных верхнего уровня.
 *
 * @param  array<string, mixed> $vars
 * @return list<object>
 */
function __rcb_instances(string $class, array $vars): array
{
    $seen   = new SplObjectStorage();
    $found  = [];
    $stack  = array_values($vars);
    while ($stack !== []) {
        $value = array_pop($stack);
        if (is_array($value)) {
            array_push($stack, ...array_values($value));
            continue;
        }

        if (!is_object($value) || $seen->contains($value)) {
            continue;
        }

        $seen->attach($value);
        if ($value instanceof $class) {
            $found[] = $value;
        }

        if ($value instanceof Closure) {
            $function = new ReflectionFunction($value);
            array_push($stack, ...array_values($function->getClosureUsedVariables()));
            $stack[] = $function->getClosureThis();
            continue;
        }

        for ($reflection = new ReflectionObject($value); $reflection !== false; $reflection = $reflection->getParentClass()) {
            foreach ($reflection->getProperties() as $property) {
                if (!$property->isStatic() && !$property->isVirtual() && $property->getDeclaringClass()->name === $reflection->name && $property->isInitialized($value)) {
                    $stack[] = $property->getValue($value);
                }
            }
        }
    }

    return $found;
}

/**
 * Прогревающие вызовы по очереди, каждый — в форке: удачный продолжает цепочку в своём
 * процессе (и в конце записывает снимок), неудачный завершается без записи — тогда
 * родитель пропускает этот вызов и продолжает со следующего.
 *
 * @param array<string, mixed> $vars
 */
function __rcb_warm(array $vars, int $index): never
{
    $state = __rcb_state();
    $list  = $state->config['warmup'];
    if ($index >= count($list)) {
        $encoder = new __RcbSnapshotEncoder($state->config['classes']);
        $encoded = [];
        foreach ($vars as $name => $value) {
            $encoded[$name] = $encoder->encode($value);
        }

        $statics         = $encoder->statics();
        $state->dump     = ['vars' => $encoded, 'statics' => $statics, 'objects' => $encoder->objects];
        $state->warming  = null;
        __rcb_finish('warmup');
        __rcb_kill();
    }

    $pid = pcntl_fork();
    if ($pid === 0) {
        $state->warming = $index;
        __rcb_trace('warmup ' . $index . ': ' . $list[$index]);
        try {
            $code = (string) $list[$index];
            if (preg_match('/^\\\\?([A-Za-z_][A-Za-z0-9_]*)(->.*)$/s', $code, $match) === 1) {
                // «Класс->вызов»: у каждого объекта класса в куче (имена переменных могли измениться)
                foreach (__rcb_instances($match[1], $vars) as $__rcbObject) {
                    eval('$__rcbObject' . $match[2] . ';');
                }
            } else {
                (static function (array $__rcbVars, string $__rcbCode): void {
                    extract($__rcbVars);
                    eval('return ' . $__rcbCode . ';');
                })($vars, $code);
            }
        } catch (Throwable $throwable) {
            file_put_contents($state->out . '.fail' . $index, 'exception: ' . $throwable->getMessage());
            __rcb_kill();
        }

        __rcb_warm($vars, $index + 1);
    }

    if ($pid > 0) {
        while (pcntl_waitpid($pid, $status) === -1 && pcntl_get_last_error() === PCNTL_EINTR) {
            // сигнал прервал ожидание — ждём дальше
        }

        clearstatcache(true, $state->out); // размер файла мог быть закэширован прошлой проверкой

        __rcb_trace('child of warmup ' . $index . ' ended, snapshot ' . (is_file($state->out) && (int) filesize($state->out) > 0 ? 'written' : 'not written'));
        if (is_file($state->out) && (int) filesize($state->out) > 0) {
            __rcb_kill(); // цепочка потомка дошла до конца и записала снимок
        }
    }

    __rcb_warm($vars, $index + 1);
}

function __rcb_finish(string $reason): void
{
    $state = __rcb_state();
    if ($state->done) {
        return;
    }

    __rcb_trace('finish: ' . $reason);
    $state->done = true;
    $failures    = [];
    foreach (glob($state->out . '.fail*') ?: [] as $file) {
        $failures[(int) substr($file, strlen($state->out . '.fail'))] = (string) file_get_contents($file);
        unlink($file);
    }

    file_put_contents($state->out, serialize([
        'warmupFailures' => $failures,
        'boundary' => $state->stmt,
        'reason'   => $reason,
        'effects'  => array_map(static fn(mixed $effects): array => is_array($effects) ? array_keys($effects) : [], $state->effects),
        'dump'     => $state->dump,
    ]));
}

/** Отладка: RCB_SNAPSHOT_TRACE=файл — журнал процессов снимка. */
function __rcb_trace(string $message): void
{
    $file = getenv('RCB_SNAPSHOT_TRACE');
    if (is_string($file) && $file !== '') {
        file_put_contents($file, posix_getpid() . ' ' . $message . "\n", FILE_APPEND);
    }
}

function __rcb_kill(): never
{
    if (function_exists('posix_kill')) {
        posix_kill(posix_getpid(), 9);
    }

    exit(0);
}

/** Конец программы: все инструкции выполнены без запрещённых эффектов. */
function __rcb_end(): never
{
    __rcb_finish('end');
    __rcb_kill();
}

final class __RcbSnapshotEncoder
{
    /** @var array<int, mixed> */
    public array $objects = [];

    /** @var SplObjectStorage<object, int> */
    private SplObjectStorage $ids;

    /** @param list<string> $classes lowercase имена классов программы */
    public function __construct(private readonly array $classes)
    {
        $this->ids = new SplObjectStorage();
    }

    public function encode(mixed $value, int $depth = 0): array
    {
        return match (true) {
            $depth > 200 => ['x', 'depth'],
            $value === null, is_bool($value), is_int($value), is_string($value) => ['s', $value],
            is_float($value) => is_finite($value) ? ['s', $value] : ['x', 'float'],
            is_array($value) => ['a', array_map(fn(int|string $k): array => [$k, $this->encode($value[$k], $depth + 1)], array_keys($value))],
            $value instanceof UnitEnum => ['e', $value::class, $value->name],
            is_object($value) => ['o', $this->object($value, $depth)],
            default => ['x', get_debug_type($value)],
        };
    }

    /** @return list<array{string, string, array}> */
    public function statics(): array
    {
        $result = [];
        foreach ($this->classes as $class) {
            if (!class_exists($class, false) && !trait_exists($class, false)) {
                continue;
            }

            $reflection = new ReflectionClass($class);
            foreach ($reflection->getProperties(ReflectionProperty::IS_STATIC) as $property) {
                if ($property->getDeclaringClass()->name !== $reflection->name || !$property->isInitialized()) {
                    continue;
                }

                $value = $property->getValue();
                if ($property->hasDefaultValue() && $value === $property->getDefaultValue()) {
                    continue;
                }

                $result[] = [$reflection->name, $property->name, $this->encode($value)];
            }
        }

        return $result;
    }

    private function object(object $object, int $depth): int
    {
        if ($this->ids->contains($object)) {
            return $this->ids[$object];
        }

        $id                 = count($this->objects);
        $this->ids[$object] = $id;
        $this->objects[$id] = ['unsupported', $object::class];
        $this->objects[$id] = $object instanceof Closure ? $this->closure($object, $depth) : $this->instance($object, $depth);

        return $id;
    }

    /**
     * @param  Closure(mixed ...): mixed $closure
     * @return array<mixed>
     */
    private function closure(Closure $closure, int $depth): array
    {
        $function = new ReflectionFunction($closure);
        $this_    = $function->getClosureThis();
        $scope    = $function->getClosureScopeClass()?->name;
        $used     = [];
        foreach ($function->getClosureUsedVariables() as $name => $value) {
            $used[$name] = $this->encode($value, $depth + 1);
        }

        $common = [$this_ === null ? null : $this->encode($this_, $depth + 1), $scope, $used];
        $state  = __rcb_state();
        $id     = $state->closures[$closure] ?? null;
        $lines  = $state->closureLines[$function->getStartLine() . ':' . $function->getEndLine()] ?? [];
        if ($id === null && count($lines) === 1 && str_contains($function->name, '{closure')) {
            $id = array_key_first($lines);
        }

        if ($id !== null) {
            return ['closure', $id, ...$common];
        }

        // Первоклассный callable: strlen(...), $obj->m(...), C::m(...)
        return str_contains($function->name, '{closure')
            ? ['unsupported', 'closure at line ' . $function->getStartLine() . ' (' . count($lines) . ' candidates)']
            : ['fcc', $function->name, ...$common];
    }

    private function instance(object $object, int $depth): array
    {
        $reflection = new ReflectionObject($object);
        if (!in_array(strtolower($reflection->name), $this->classes, true)) {
            return ['unsupported', $reflection->name];
        }

        // Ленивый объект: чтение свойства запустило бы инициализацию (код) во время снимка
        if ($reflection->isUninitializedLazyObject($object)) {
            return ['unsupported', 'lazy object ' . $reflection->name];
        }

        $props    = [];
        $declared = [];
        for ($class = $reflection; $class !== false; $class = $class->getParentClass()) {
            if ($class->isInternal()) {
                return ['unsupported', 'internal parent ' . $class->name];
            }

            foreach ($class->getProperties() as $property) {
                if ($property->isStatic() || $property->getDeclaringClass()->name !== $class->name) {
                    continue;
                }

                $declared[$property->name] = true;
                if ($property->isVirtual()) {
                    continue;
                }

                if (!$property->isInitialized($object)) {
                    if ($property->hasDefaultValue()) {
                        $props[] = [$class->name, $property->name, ['unset']];
                    }

                    continue;
                }

                $value = $property->hasHooks() ? $property->getRawValue($object) : $property->getValue($object);
                if ($property->hasDefaultValue() && $value === $property->getDefaultValue()) {
                    continue;
                }

                $props[] = [$class->name, $property->name, $this->encode($value, $depth + 1)];
            }
        }

        foreach (array_keys((array) $object) as $key) {
            if (!str_contains((string) $key, "\0") && !isset($declared[$key])) {
                return ['unsupported', 'dynamic property ' . $key];
            }
        }

        return ['object', $reflection->name, $props];
    }
}

set_error_handler(static function (int $errno, string $message): bool {
    if ((error_reporting() & $errno) === 0) {
        return false;
    }

    __rcb_fx('error: ' . $message);

    return false;
});

register_shutdown_function(static function (): void {
    $state = __rcb_state();
    if ($state->warming !== null) {
        // Прогрев упал (фатальная ошибка, exit): вызов отбрасывается
        $error = error_get_last();
        file_put_contents($state->out . '.fail' . $state->warming, 'shutdown: ' . ($error['message'] ?? 'exit'));

        return;
    }

    // Исключение, фатальная ошибка или exit на инструкции — граница перед ней
    __rcb_finish('shutdown');
});
