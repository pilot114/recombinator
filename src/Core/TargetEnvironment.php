<?php

declare(strict_types=1);

namespace Recombinator\Core;

/**
 * Допущения о целевом сервере, на которых правила могут принимать решения.
 *
 * Описание прикладывается к прогону (`environment` в examples/meta.json или в запросе):
 *
 *   {
 *     "php": "8.5.10",                       // PHP_VERSION, PHP_VERSION_ID, phpversion()
 *     "os": "Linux",                         // PHP_OS_FAMILY (и PHP_OS)
 *     "sapi": "fpm-fcgi",                    // PHP_SAPI, php_sapi_name()
 *     "entry": "{project}/public/index.php", // путь входного файла на сервере: __DIR__
 *     "extensions": ["pdo_sqlite", ...],     // закрытый список: других расширений нет
 *     "env": {"APP_ENV": "prod", "DEBUG": null}, // getenv('APP_ENV'), $_SERVER['APP_ENV']; null — переменной нет
 *     "ini": {"memory_limit": "256M"},       // ini_get('memory_limit')
 *     "assumptions": {"containerEntriesImmutable": true},
 *     "probes": {"doctrine": "…return $entityManager;"},  // см. DoctrineMetadataProbe
 *     "invariants": {"Cls": {"prop": значение}}         // значение свойства у всех объектов
 *     "profile": [{"method": "GET", "uri": "/users"}]    // запросы для пробы выполнения (ProfileGuardVisitor)
 *     "warmup": ["Container->get('EM')", "$cnt->get('x')"] // вызовы при снимке кучи (HeapSnapshotVisitor):
 *                                                       // «Класс->…» — у каждого объекта класса, иначе — выражение
 *   }
 *
 * Любое поле можно опустить — тогда правила о нём ничего не предполагают.
 * `{project}` в entry заменяется каталогом проекта (см. withProjectDir): сервер —
 * копия проекта, исходники лежат там же.
 *
 * Допущения о коде (`assumptions`):
 * - containerEntriesImmutable — записи DI-контейнера, заданные литеральным ключом при
 *   сборке, никто не перезаписывает через вызов с вычисляемым ключом или динамическим
 *   именем метода (см. ContainerEntryVisitor).
 */
final readonly class TargetEnvironment
{
    /** @var array<string, true>|null lowercase имена расширений; null — список не задан */
    public ?array $extensions;

    /**
     * @param array<string, string|null> $env
     * @param array<string, string> $ini
     * @param array<string, bool>   $assumptions
     * @param array<string, string> $probes      вид пробы → PHP-код, выполняемый в каталоге проекта
     * @param array<string, array<string, mixed>> $invariants плоское имя класса → свойство → значение
     * @param list<array<string, string>>        $profile    запросы для пробы: method, uri
     * @param list<string>                       $warmup     выражения над переменными верхнего уровня, выполняемые при
     *                                                       снимке кучи: ленивое создание (фабрики контейнера), не зависящее
     *                                                       от запроса; вызов с побочным эффектом отбрасывается
     */
    public function __construct(
        public ?string $php = null,
        public ?string $os = null,
        public ?string $sapi = null,
        ?array $extensions = null,
        public ?string $entry = null,
        public array $env = [],
        public array $ini = [],
        public array $assumptions = [],
        public array $probes = [],
        public array $invariants = [],
        public array $profile = [],
        public array $warmup = [],
    ) {
        $this->extensions = $extensions === null
            ? null
            : array_fill_keys(array_map(strtolower(...), array_values(array_filter($extensions, is_string(...)))), true);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $strings = static fn(mixed $map): array => is_array($map)
            ? array_map(strval(...), array_filter($map, static fn(mixed $v, mixed $k): bool => is_string($k) && is_scalar($v), ARRAY_FILTER_USE_BOTH))
            : [];

        return new self(
            php: is_string($data['php'] ?? null) ? $data['php'] : null,
            os: is_string($data['os'] ?? null) ? $data['os'] : null,
            sapi: is_string($data['sapi'] ?? null) ? $data['sapi'] : null,
            extensions: is_array($data['extensions'] ?? null) ? array_values(array_filter($data['extensions'], is_string(...))) : null,
            entry: is_string($data['entry'] ?? null) ? $data['entry'] : null,
            env: is_array($data['env'] ?? null)
                ? array_map(static fn(mixed $v): ?string => $v === null ? null : (string) $v, array_filter(
                    $data['env'],
                    static fn(mixed $v, mixed $k): bool => is_string($k) && ($v === null || is_scalar($v)),
                    ARRAY_FILTER_USE_BOTH,
                ))
                : [],
            ini: $strings($data['ini'] ?? null),
            assumptions: is_array($data['assumptions'] ?? null)
                ? array_map(boolval(...), array_filter($data['assumptions'], is_string(...), ARRAY_FILTER_USE_KEY))
                : [],
            probes: $strings($data['probes'] ?? null),
            invariants: is_array($data['invariants'] ?? null)
                ? array_filter($data['invariants'], static fn(mixed $v, mixed $k): bool => is_string($k) && is_array($v), ARRAY_FILTER_USE_BOTH)
                : [],
            profile: self::requests($data['profile'] ?? null),
            warmup: is_array($data['warmup'] ?? null) ? array_values(array_filter($data['warmup'], is_string(...))) : [],
        );
    }

    public function isEmpty(): bool
    {
        return $this->php === null && $this->os === null && $this->sapi === null && $this->extensions === null && $this->entry === null
            && $this->env === [] && $this->ini === [] && $this->assumptions === [] && $this->probes === [] && $this->invariants === [] && $this->profile === [] && $this->warmup === [];
    }

    /** То же описание, где `{project}` во входном файле — указанный каталог. */
    public function withProjectDir(string $projectDir): self
    {
        return new self(
            php: $this->php,
            os: $this->os,
            sapi: $this->sapi,
            extensions: $this->extensions === null ? null : array_map(strval(...), array_keys($this->extensions)),
            entry: $this->entry === null ? null : str_replace('{project}', rtrim($projectDir, '/'), $this->entry),
            env: $this->env,
            ini: $this->ini,
            assumptions: $this->assumptions,
            probes: $this->probes,
            invariants: $this->invariants,
            profile: $this->profile,
            warmup: $this->warmup,
        );
    }

    /** @return list<array<string, string>> */
    private static function requests(mixed $profile): array
    {
        $result = [];
        foreach (is_array($profile) ? $profile : [] as $request) {
            if (!is_array($request)) {
                continue;
            }

            $entry = [];
            foreach ($request as $key => $value) {
                if (is_string($key) && is_scalar($value)) {
                    $entry[$key] = (string) $value;
                }
            }

            $result[] = $entry;
        }

        return $result;
    }

    /**
     * То же описание с добавленными инвариантами (результат проб). Явно заданные в
     * описании инварианты важнее.
     *
     * @param array<string, array<string, mixed>> $invariants
     */
    public function withInvariants(array $invariants): self
    {
        $merged = $invariants;
        foreach ($this->invariants as $class => $properties) {
            $merged[$class] = [...$merged[$class] ?? [], ...$properties];
        }

        return new self(
            php: $this->php,
            os: $this->os,
            sapi: $this->sapi,
            extensions: $this->extensions === null ? null : array_map(strval(...), array_keys($this->extensions)),
            entry: $this->entry,
            env: $this->env,
            ini: $this->ini,
            assumptions: $this->assumptions,
            probes: $this->probes,
            invariants: $merged,
            profile: $this->profile,
            warmup: $this->warmup,
        );
    }

    /** Значение инварианта или null, если его нет; второй элемент — найдено ли. */
    public function invariant(string $class, string $property): array
    {
        $properties = $this->invariants[$class] ?? $this->invariants[strtolower($class)] ?? null;

        return is_array($properties) && array_key_exists($property, $properties) ? [$properties[$property], true] : [null, false];
    }

    /** Каталог входного файла на сервере (__DIR__ кода верхнего уровня). */
    public function entryDir(): ?string
    {
        return $this->entry === null || str_contains($this->entry, '{project}') ? null : dirname($this->entry);
    }

    public function assumes(string $name): bool
    {
        return $this->assumptions[$name] ?? false;
    }

    /** true/false — расширение есть/нет; null — список расширений не задан. */
    public function hasExtension(string $name): ?bool
    {
        return $this->extensions === null ? null : isset($this->extensions[strtolower($name)]);
    }

    /**
     * Известны ли все классы и функции перечисленных расширений: каждое из них есть и
     * в PHP, который выполняет анализ (иначе их содержимое не узнать рефлексией).
     */
    public function extensionsKnown(): bool
    {
        if ($this->extensions === null) {
            return false;
        }

        $local = array_fill_keys(array_map(strtolower(...), get_loaded_extensions()), true);

        return array_all(array_keys($this->extensions), static fn(int|string $ext): bool => isset($local[(string) $ext]));
    }

    /** PHP_VERSION_ID для версии вида 8.5.10. */
    public function phpVersionId(): ?int
    {
        if ($this->php === null || preg_match('/^(\d+)\.(\d+)(?:\.(\d+))?/', $this->php, $m) !== 1) {
            return null;
        }

        return (int) $m[1] * 10000 + (int) $m[2] * 100 + (int) ($m[3] ?? 0);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'php'         => $this->php,
            'os'          => $this->os,
            'sapi'        => $this->sapi,
            'entry'       => $this->entry,
            'extensions'  => $this->extensions === null ? null : array_keys($this->extensions),
            'env'         => $this->env,
            'ini'         => $this->ini,
            'assumptions' => $this->assumptions,
            'probes'      => $this->probes,
            'invariants'  => $this->invariants,
            'profile'     => $this->profile,
            'warmup'      => $this->warmup,
        ], static fn(mixed $v): bool => $v !== null && $v !== []);
    }
}
