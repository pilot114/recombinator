<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;
use Recombinator\Core\TargetEnvironment;

/**
 * Сворачивает проверки окружения по допущениям о целевом сервере (TargetEnvironment):
 *
 *   class_exists('Relay\Relay')      → false   (расширения relay нет в списке)
 *   extension_loaded('pdo_sqlite')   → true
 *   function_exists('apcu_fetch')    → false
 *   \PHP_VERSION_ID >= 80400         → 80510 >= 80400
 *   getenv('APP_ENV')                → 'prod'
 *
 * Существование класса или функции по литеральному имени:
 * - объявлен в программе — решают другие правила (ClassExistsFoldVisitor);
 * - объявлен в файле проекта — найдёт автозагрузка: true (для своего вида: класс,
 *   интерфейс, трейт, enum); функция из файла проекта (полифил) — не трогается;
 * - встроен в PHP — по расширению, которое его даёт: есть ли оно в списке;
 * - иначе false, если список расширений задан и все они известны анализатору.
 *
 * Проверки, которые ExternalParentGuardVisitor оставляет до выполнения (runtimeCheck),
 * тоже сворачиваются: отсутствие родителя здесь — допущение о сервере.
 */
#[VisitorMeta('Свёртка проверок окружения по допущениям о целевом сервере')]
class TargetEnvironmentVisitor extends BaseVisitor
{
    private const array KINDS = [
        'class_exists'     => ['class', 'enum'],
        'interface_exists' => ['interface'],
        'trait_exists'     => ['trait'],
        'enum_exists'      => ['enum'],
    ];

    /** @var array<string, array{classes: array<string, string>, functions: array<string, true>}> */
    private static array $declaredCache = [];

    /** @var array<string, true> lowercase имена классов программы */
    private array $program = [];

    /** @var array<string, true> lowercase имена функций программы */
    private array $programFunctions = [];

    /** @var array<string, int> сколько раз функция объявлена в программе */
    private array $functionCounts = [];

    /** @var array<string, true> функции, объявленные на верхнем уровне без условия */
    private array $topFunctions = [];

    /** @var array<string, list<Node\Stmt\ClassLike>> lowercase имя → объявления */
    private array $classNodes = [];

    /** @param array<string, string> $fileMap файлы проекта (путь → код) */
    public function __construct(
        private readonly TargetEnvironment $environment,
        private readonly array $fileMap = [],
        private readonly ?string $projectDir = null,
    ) {
    }

    /** Программа замкнута — файлы проекта вне неё не выполняются (см. beforeTraverse). */
    private bool $closed = false;

    #[\Override]
    public function beforeTraverse(array $nodes): ?array
    {
        parent::beforeTraverse($nodes);
        $this->program          = [];
        $this->programFunctions = [];
        $this->topFunctions     = [];
        $this->functionCounts   = [];
        $this->classNodes       = [];
        foreach ($this->findNode(Node::class) as $node) {
            if ($node instanceof Node\Stmt\ClassLike && $node->name instanceof Node\Identifier) {
                $this->program[$node->name->toLowerString()]    = true;
                $this->classNodes[$node->name->toLowerString()][] = $node;
            } elseif ($node instanceof Node\Stmt\Function_) {
                $this->programFunctions[$node->name->toLowerString()] = true;
                $this->functionCounts[$node->name->toLowerString()]   = ($this->functionCounts[$node->name->toLowerString()] ?? 0) + 1;
            }
        }

        // Программа замкнута: автозагрузчик Composer убран, невстроенных подключений нет —
        // файлы проекта, кроме самой программы, не выполняются и функций не объявляют
        $this->closed = !$this->hasPendingIncludes() && !array_any(
            $this->findNode(Node\Expr\Include_::class),
            static fn(Node\Expr\Include_ $i): bool => $i->getAttribute('runtimeAutoload') === true,
        );

        // Функция, объявленная на верхнем уровне без условия, существует с загрузки файла
        foreach ($nodes as $node) {
            foreach ($node instanceof Node\Stmt\Namespace_ ? $node->stmts : [$node] as $stmt) {
                if ($stmt instanceof Node\Stmt\Function_) {
                    $this->topFunctions[$stmt->name->toLowerString()] = true;
                }
            }
        }

        return null;
    }

    public function enterNode(Node $node): ?Node
    {
        if ($this->environment->isEmpty()) {
            return null;
        }

        $value = match (true) {
            // __DIR__ кода верхнего уровня: встроенный код FileMapIncludeVisitor уже перевёл на каталог входного файла
            $node instanceof Node\Scalar\MagicConst\Dir => $this->insideInclude($node) ? null : $this->environment->entryDir(),
            $node instanceof Node\Expr\ConstFetch => $this->constant($node->name->toString()),
            $node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name => $this->call(strtolower(ltrim($node->name->toString(), '\\')), $node),
            default => null,
        };

        return $value === null ? null : $this->literal($value, $node);
    }

    /** version_compare() с литералами — в том числе подставленными выше (phpversion, PHP_VERSION). */
    #[\Override]
    public function leaveNode(Node $node): int|Node|array|null
    {
        if (
            !$this->environment->isEmpty() && $node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name
            && strtolower(ltrim($node->name->toString(), '\\')) === 'version_compare'
        ) {
            $values = [];
            foreach ($node->getRawArgs() as $arg) {
                $value = $arg instanceof Node\Arg && !$arg->unpack && !$arg->name instanceof \PhpParser\Node\Identifier ? $this->literalValue($arg->value) : null;
                if ($value === null) {
                    return parent::leaveNode($node);
                }

                $values[] = $value;
            }

            if (count($values) === 2 || count($values) === 3) {
                $result = count($values) === 2
                    ? version_compare((string) $values[0], (string) $values[1])
                    : version_compare((string) $values[0], (string) $values[1], (string) $values[2]);

                return $this->literal($result, $node);
            }
        }

        return parent::leaveNode($node);
    }

    /** Значение литерала (строка, число, true/false); null — не литерал. */
    private function literalValue(Node\Expr $expr): string|int|float|bool|null
    {
        return match (true) {
            $expr instanceof Node\Scalar\String_, $expr instanceof Node\Scalar\Int_, $expr instanceof Node\Scalar\Float_ => $expr->value,
            $expr instanceof Node\Expr\ConstFetch && in_array($expr->name->toLowerString(), ['true', 'false'], true) => $expr->name->toLowerString() === 'true',
            default => null,
        };
    }

    private function constant(string $name): string|int|null
    {
        $versionId = $this->environment->phpVersionId();
        $php       = $this->environment->php;

        return match (ltrim($name, '\\')) {
            'PHP_VERSION' => $php,
            'PHP_VERSION_ID' => $versionId,
            'PHP_MAJOR_VERSION' => $versionId === null ? null : intdiv($versionId, 10000),
            'PHP_MINOR_VERSION' => $versionId === null ? null : intdiv($versionId % 10000, 100),
            'PHP_RELEASE_VERSION' => $versionId === null ? null : $versionId % 100,
            'PHP_OS_FAMILY' => $this->environment->os,
            'DIRECTORY_SEPARATOR' => $this->environment->os === null ? null : ($this->environment->os === 'Windows' ? '\\' : '/'),
            'PHP_SAPI' => $this->environment->sapi,
            default => null,
        };
    }

    private function call(string $function, Node\Expr\FuncCall $call): string|bool|null
    {
        $args     = $call->getRawArgs();
        $argument = count($args) === 1 && $args[0] instanceof Node\Arg && !$args[0]->unpack && $args[0]->value instanceof Node\Scalar\String_
            ? $args[0]->value->value
            : null;

        if ($args === []) {
            return match ($function) {
                'phpversion' => $this->environment->php,
                'php_sapi_name' => $this->environment->sapi,
                default => null,
            };
        }

        if ($argument === null) {
            return null;
        }

        return match ($function) {
            'class_exists', 'interface_exists', 'trait_exists', 'enum_exists' => $this->classExists(ltrim($argument, '\\'), self::KINDS[$function]),
            'extension_loaded' => $this->environment->hasExtension($argument),
            'phpversion' => $this->extensionVersion($argument),
            'function_exists' => $this->ownGuard($call, ltrim($argument, '\\')) ? false : $this->functionExists(ltrim($argument, '\\')),
            // null в описании — переменной на сервере нет
            'getenv' => array_key_exists($argument, $this->environment->env) ? $this->environment->env[$argument] ?? false : null,
            'file_exists', 'is_file', 'is_readable' => $this->isSourceFile($argument) ? true : null,
            'ini_get' => $this->iniValue($argument),
            'defined' => str_contains($argument, '::') ? $this->classConstantDefined($argument) : $this->constantDefined($call, ltrim($argument, '\\')),
            default => null,
        };
    }

    /** @param list<string> $kinds */
    private function classExists(string $name, array $kinds): ?bool
    {
        $lower = strtolower($name);
        if (isset($this->program[$lower]) || isset($this->program[str_replace('\\', '_', $lower)])) {
            return null;
        }

        $declared = $this->declared()['classes'][$lower] ?? null;
        if ($declared !== null) {
            return in_array($declared, $kinds, true);
        }

        if (class_exists($name, false) || interface_exists($name, false) || trait_exists($name, false)) {
            $reflection = new \ReflectionClass($name);
            if (!$reflection->isInternal()) {
                return null;
            }

            $kind = match (true) {
                $reflection->isEnum() => 'enum',
                $reflection->isInterface() => 'interface',
                $reflection->isTrait() => 'trait',
                default => 'class',
            };

            return $this->environment->hasExtension((string) $reflection->getExtensionName()) === true && in_array($kind, $kinds, true)
                ? true
                : ($this->environment->extensions === null ? null : false);
        }

        return $this->environment->extensionsKnown() ? false : null;
    }

    /**
     * Путь подключения остаётся относительным: его разбирают FileMapIncludeVisitor и
     * PolyfillRequireVisitor, а абсолютный путь они сопоставили бы с картой файлов неверно.
     */
    private function insideInclude(Node $node): bool
    {
        for ($current = $node->getAttribute('parent'); $current instanceof Node && !$current instanceof Node\Stmt; $current = $current->getAttribute('parent')) {
            if ($current instanceof Node\Expr\Include_) {
                return true;
            }
        }

        return false;
    }

    /**
     * Путь — файл исходников проекта (есть в карте файлов): на сервере он есть, ведь
     * сервер — копия проекта. Отсутствие не утверждается: файлы и каталоги создаются
     * во время работы (кэш, база).
     */
    private function isSourceFile(string $path): bool
    {
        if ($this->projectDir === null || $this->environment->entry === null || !str_starts_with($path, '/')) {
            return false;
        }

        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '..') {
                array_pop($parts);
            } elseif ($part !== '' && $part !== '.') {
                $parts[] = $part;
            }
        }

        $normalized = '/' . implode('/', $parts);
        $root       = rtrim($this->projectDir, '/') . '/';

        return str_starts_with($normalized, $root) && isset($this->fileMap[substr($normalized, strlen($root))]);
    }

    /** phpversion('ext'): false без расширения; версия — только если оно есть и у анализатора. */
    private function extensionVersion(string $extension): string|false|null
    {
        return match ($this->environment->hasExtension($extension)) {
            false => false,
            true => phpversion($extension) === false ? null : phpversion($extension),
            null => null,
        };
    }

    /**
     * ini_get(): значение из описания; директива, которую не регистрирует ни одно из
     * перечисленных расширений, на сервере не существует — false.
     */
    private function iniValue(string $directive): string|false|null
    {
        if (isset($this->environment->ini[$directive])) {
            return $this->environment->ini[$directive];
        }

        if (!$this->environment->extensionsKnown()) {
            return null;
        }

        foreach (array_keys($this->environment->extensions ?? []) as $extension) {
            $registered = @ini_get_all((string) $extension, false);
            if (is_array($registered) && array_key_exists($directive, $registered)) {
                return null; // директива есть, значение на сервере неизвестно
            }
        }

        // Директивы ядра не принадлежат расширению из списка get_loaded_extensions()
        $core = @ini_get_all('core', false);

        return is_array($core) && array_key_exists($directive, $core) ? null : false;
    }

    /**
     * defined('ИМЯ') для глобальной константы: встроенная константа ядра или расширения,
     * которое есть на сервере, — true; константу программа не объявляет (и программа
     * замкнута) — false; единственное объявление под `if (!defined('ИМЯ'))` — проверка
     * выполняется до него, false.
     */
    private function constantDefined(Node\Expr\FuncCall $call, string $name): ?bool
    {
        $declarations = $this->constantDeclarations();
        if ($declarations === null) {
            return null; // define() с вычисляемым именем
        }

        $count = $declarations[$name] ?? 0;
        if ($count === 1 && $this->constantGuard($call, $name)) {
            return false;
        }

        if ($count > 0) {
            return null;
        }

        $extension = $this->builtinConstantExtension($name);
        if ($extension !== null) {
            return $this->environment->hasExtension($extension);
        }

        // Не встроенная — если анализатор знает все константы сервера (те же версия и расширения)
        return $this->closed && $this->knowsServerConstants() ? false : null;
    }

    private function knowsServerConstants(): bool
    {
        if (
            !$this->environment->extensionsKnown() || $this->environment->phpVersionId() === null
            || intdiv($this->environment->phpVersionId(), 100) !== intdiv(\PHP_VERSION_ID, 100)
        ) {
            return false;
        }

        $loaded = array_map(strtolower(...), get_loaded_extensions());
        return array_all($this->serverExtensions(), fn(string $extension): bool => in_array(strtolower($extension), $loaded, true));
    }

    /** @return list<string> */
    private function serverExtensions(): array
    {
        return array_map(strval(...), array_keys($this->environment->extensions ?? []));
    }

    /** @return array<string, int>|null имя → число объявлений в программе; null — имя вычисляется */
    private function constantDeclarations(): ?array
    {
        $result = [];
        foreach ($this->findNode(Node::class) as $node) {
            if ($node instanceof Node\Stmt\Const_) {
                foreach ($node->consts as $const) {
                    $result[$const->name->toString()] = ($result[$const->name->toString()] ?? 0) + 1;
                }
            } elseif ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name && ltrim($node->name->toLowerString(), '\\') === 'define') {
                $first = $node->isPartialFunctionApplication() ? null : ($node->getArgs()[0] ?? null)?->value;
                if (!$first instanceof Node\Scalar\String_) {
                    return null;
                }

                $result[ltrim($first->value, '\\')] = ($result[ltrim($first->value, '\\')] ?? 0) + 1;
            }
        }

        return $result;
    }

    /** `if (!defined('X')) { … define('X', …); … }` — защита единственного объявления. */
    private function constantGuard(Node\Expr\FuncCall $call, string $name): bool
    {
        $not = $call->getAttribute('parent');
        $if  = $not instanceof Node\Expr\BooleanNot ? $not->getAttribute('parent') : null;
        if (!$if instanceof Node\Stmt\If_ || $if->cond !== $not || $if->elseifs !== [] || $if->else instanceof Node\Stmt\Else_) {
            return false;
        }

        foreach ($if->stmts as $stmt) {
            $define = $stmt instanceof Node\Stmt\Expression ? $stmt->expr : null;
            if (
                $define instanceof Node\Expr\FuncCall && $define->name instanceof Node\Name && ltrim($define->name->toLowerString(), '\\') === 'define'
                && !$define->isPartialFunctionApplication()
                && ($define->getArgs()[0] ?? null)?->value instanceof Node\Scalar\String_ && ltrim($define->getArgs()[0]->value->value, '\\') === $name
            ) {
                return true;
            }

            if ($stmt instanceof Node\Stmt\Const_ && array_any($stmt->consts, static fn(Node\Const_ $c): bool => $c->name->toString() === $name)) {
                return true;
            }
        }

        return false;
    }

    /** Расширение, которое объявляет встроенную константу (в этой версии PHP); null — не встроенная. */
    private function builtinConstantExtension(string $name): ?string
    {
        static $byName = null;
        if ($byName === null) {
            $byName = [];
            foreach (get_defined_constants(true) as $extension => $constants) {
                if ($extension === 'user') {
                    continue;
                }

                foreach (array_keys($constants) as $constant) {
                    $byName[$constant] = $extension;
                }
            }
        }

        if (!isset($byName[$name])) {
            return null;
        }

        // Ядро: те же версии PHP — та же константа
        $sameVersion = $this->environment->phpVersionId() !== null && intdiv($this->environment->phpVersionId(), 100) === intdiv(\PHP_VERSION_ID, 100);

        return $sameVersion ? $byName[$name] : null;
    }

    /** defined('Класс::КОНСТАНТА') для класса программы: по объявлению в нём или предках. */
    private function classConstantDefined(string $name): ?bool
    {
        if (!str_contains($name, '::')) {
            return null;
        }

        [$class, $constant] = explode('::', ltrim($name, '\\'), 2);
        $seen               = [];
        $queue              = [strtolower($class)];
        while ($queue !== []) {
            $current = array_pop($queue);
            if (isset($seen[$current])) {
                continue;
            }

            $seen[$current] = true;
            $nodes          = $this->classNodes[$current] ?? [];
            if (count($nodes) !== 1) {
                return null; // не из программы или объявлен условно в нескольких вариантах
            }

            $node = $nodes[0];
            foreach ($node->getConstants() as $group) {
                foreach ($group->consts as $const) {
                    if ($const->name->toString() === $constant) {
                        return true;
                    }
                }
            }

            if ($node instanceof Node\Stmt\Enum_) {
                foreach ($node->stmts as $stmt) {
                    if ($stmt instanceof Node\Stmt\EnumCase && $stmt->name->toString() === $constant) {
                        return true;
                    }
                }
            }

            $parents = match (true) {
                $node instanceof Node\Stmt\Class_ => [$node->extends, ...$node->implements],
                $node instanceof Node\Stmt\Interface_ => $node->extends,
                $node instanceof Node\Stmt\Enum_ => $node->implements,
                default => [],
            };
            foreach ($parents as $parent) {
                if ($parent instanceof Node\Name) {
                    $lower = strtolower(ltrim($parent->toString(), '\\'));
                    if (!isset($this->classNodes[$lower])) {
                        return null; // внешний предок: его константы неизвестны
                    }

                    $queue[] = $lower;
                }
            }
        }

        return false;
    }

    /**
     * `if (!function_exists('f')) { function f() {...} }` — защита от повторного объявления
     * единственной функции программы, которую больше никто не объявляет: проверка
     * выполняется до объявления, функции ещё нет.
     */
    private function ownGuard(Node\Expr\FuncCall $call, string $name): bool
    {
        $not = $call->getAttribute('parent');
        $if  = $not instanceof Node\Expr\BooleanNot ? $not->getAttribute('parent') : null;
        if (!$if instanceof Node\Stmt\If_ || $if->cond !== $not || $if->elseifs !== [] || $if->else instanceof \PhpParser\Node\Stmt\Else_) {
            return false;
        }

        $lower = strtolower($name);
        if ($this->projectDeclares($lower) || ($this->functionCounts[$lower] ?? 0) !== 1) {
            return false;
        }

        return array_any($if->stmts, static fn(Node\Stmt $s): bool => $s instanceof Node\Stmt\Function_ && $s->name->toLowerString() === $lower);
    }

    /** Функцию может объявить файл проекта вне программы (пока программа не замкнута). */
    private function projectDeclares(string $lower): bool
    {
        return !$this->closed && isset($this->declared()['functions'][$lower]);
    }

    private function functionExists(string $name): ?bool
    {
        $lower = strtolower($name);
        if (isset($this->topFunctions[$lower]) && !$this->projectDeclares($lower)) {
            return true;
        }

        // Встроенная функция расширения, которое есть на сервере, существует и при полифиле:
        // полифил объявлен под !function_exists. Только если версии PHP совпадают (до минорной):
        // набор функций ядра от версии зависит
        $sameVersion = $this->environment->phpVersionId() !== null && intdiv($this->environment->phpVersionId(), 100) === intdiv(\PHP_VERSION_ID, 100);
        if ($sameVersion && function_exists($name) && new \ReflectionFunction($name)->isInternal()) {
            $has = $this->environment->hasExtension((string) new \ReflectionFunction($name)->getExtensionName());
            if ($has === true) {
                return true;
            }
        }

        if (isset($this->programFunctions[$lower]) || $this->projectDeclares($lower)) {
            return null; // своя функция или полифил из файла проекта — объявление может быть условным
        }

        if (function_exists($name)) {
            $function = new \ReflectionFunction($name);
            if (!$function->isInternal()) {
                return null;
            }

            $has = $this->environment->hasExtension((string) $function->getExtensionName());

            return $has ?? null;
        }

        return $this->environment->extensionsKnown() ? false : null;
    }

    /**
     * Классы (полное имя → вид) и функции, объявленные в файлах проекта.
     *
     * @return array{classes: array<string, string>, functions: array<string, true>}
     */
    private function declared(): array
    {
        $key = sha1(implode("\0", array_keys($this->fileMap)));
        if (isset(self::$declaredCache[$key])) {
            return self::$declaredCache[$key];
        }

        $classes   = [];
        $functions = [];
        foreach ($this->fileMap as $code) {
            $namespace = preg_match('/(?:^|<\?php)\s*namespace\s+([\w\\\\]+)\s*[;{]/m', $code, $m) === 1 ? strtolower($m[1]) . '\\' : '';
            preg_match_all('/(?:^|[;{}])\s*(?:(?:final|abstract|readonly)\s+)*(class|interface|trait|enum)\s+(\w+)/mi', $code, $found, PREG_SET_ORDER);
            foreach ($found as [, $kind, $short]) {
                $classes[$namespace . strtolower($short)] = strtolower($kind);
            }

            preg_match_all('/(?:^|[;{}])\s*function\s+&?\s*(\w+)\s*\(/mi', $code, $fns);
            foreach ($fns[1] as $function) {
                $functions[strtolower($function)] = true;
            }
        }

        return self::$declaredCache[$key] = ['classes' => $classes, 'functions' => $functions];
    }

    private function literal(string|int|bool|null $value, Node $node): Node\Expr
    {
        $attributes = $node->getAttributes();
        unset($attributes['parent'], $attributes['next'], $attributes['previous']);

        return match (true) {
            is_bool($value) => new Node\Expr\ConstFetch(new Node\Name($value ? 'true' : 'false'), $attributes),
            is_int($value) => new Node\Scalar\Int_($value, $attributes),
            $value === null => new Node\Expr\ConstFetch(new Node\Name('null'), $attributes),
            default => new Node\Scalar\String_($value, $attributes),
        };
    }
}
