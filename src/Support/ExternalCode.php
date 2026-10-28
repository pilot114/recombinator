<?php

declare(strict_types=1);

namespace Recombinator\Support;

/**
 * Код проекта вне программы: файлы, классы которых не вошли в AST (и файлы без
 * классов). Их загрузит автозагрузка во время выполнения — провайдеры Faker по
 * вычисленному имени, — и они могут вызывать функции и методы программы, наследовать
 * её классы и реализовывать интерфейсы.
 *
 * Файл считается вошедшим, если хоть один объявленный в нём класс есть в программе
 * под плоским именем (`Foo\Bar` → `Foo_Bar`).
 *
 * С семенами из программы (forProgram) учитываются только достижимые внешние файлы:
 * выполниться может лишь код, класс которого программа может загрузить, — его полное
 * имя есть в программе строкой или именем (`new \Slim\Http\X`), или начинается с
 * литерального префикса, из которого программа собирает имя (`"Faker\\{$name}"`), — и
 * всё, на что такие файлы ссылаются сами. Имя, вычисленное целиком из данных, не видно.
 */
final class ExternalCode
{
    /** @var list<string>|null */
    private ?array $files = null;

    /** @var array<string, true>|null плоские имена классов, которые наследует внешний код */
    private ?array $parents = null;

    /** @var array<string, array{names: array<string, true>, declared: array<string, true>}> sha1 кода → разбор */
    private static array $parsed = [];

    /**
     * @param array<string, string>                                                           $fileMap  путь → код
     * @param array<string, mixed>                                                            $classes  lowercase плоские имена классов программы
     * @param array{names: array<string, true>, prefixes: array<string, true>}|null $seeds    null — весь внешний код
     */
    public function __construct(
        private readonly array $fileMap,
        private readonly array $classes,
        private readonly ?array $seeds = null,
        private readonly bool $autoloadRoots = true,
    ) {
    }

    /**
     * Файлы с классами, которые программа может загрузить автозагрузкой сама — без
     * файлов autoload_files.php (их подключение уже встроено или удалено как
     * ничего не делающее). Пусто — автозагрузчик Composer программе не нужен.
     *
     * @return list<string>
     */
    public function loadableClassFiles(): array
    {
        $own = new self($this->fileMap, $this->classes, $this->seeds, false);

        return array_values(array_filter($own->files(), static fn(string $code): bool => self::declaredNames($code) !== []));
    }

    /**
     * Внешний код с семенами из AST программы: строки, префиксы составных строк, имена
     * классов вне программы.
     *
     * @param array<string, string>          $fileMap
     * @param array<string, mixed>           $classes
     * @param array<\PhpParser\Node>          $ast
     */
    public static function forProgram(array $fileMap, array $classes, array $ast): self
    {
        $seeds  = ['names' => [], 'prefixes' => []];
        $values = new StringValues($ast);
        foreach (new \PhpParser\NodeFinder()->findInstanceOf($ast, \PhpParser\Node::class) as $node) {
            if ($node instanceof \PhpParser\Node\Scalar\String_) {
                $seeds['names'][strtolower(ltrim($node->value, '\\'))] = true;
            } elseif ($node instanceof \PhpParser\Node\Name && !isset($classes[strtolower($node->toString())]) && !self::isBuiltin($node->toString())) {
                $seeds['names'][strtolower(ltrim($node->toString(), '\\'))] = true;
            } elseif ($node instanceof \PhpParser\Node\Scalar\InterpolatedString || $node instanceof \PhpParser\Node\Expr\BinaryOp\Concat) {
                // Конечный набор значений — точные имена вместо префикса
                $exact = $values->of($node);
                if ($exact !== null) {
                    foreach ($exact as $value) {
                        $seeds['names'][strtolower(ltrim($value, '\\'))] = true;
                    }

                    continue;
                }

                $prefix = self::literalPrefix($node, 0);
                if (self::namespacePrefix($prefix)) {
                    $seeds['prefixes'][strtolower(ltrim($prefix, '\\'))] = true;
                }
            }
        }

        return new self($fileMap, $classes, $seeds);
    }

    /** Встроенный класс PHP (`\ValueError`): полифил с тем же именем не загружается. */
    private static function isBuiltin(string $name): bool
    {
        $name = ltrim($name, '\\');

        return (class_exists($name, false) || interface_exists($name, false) || enum_exists($name, false))
            && new \ReflectionClass($name)->isInternal();
    }

    /**
     * Литеральное начало строки, которую даст выражение: строка; `sprintf` с литеральным
     * форматом — до первого `%`; тернарный оператор — общее начало веток; конкатенация и
     * интерполяция — по порядку частей; переменная — общее начало всех её присваиваний
     * в функции.
     */
    private static function literalPrefix(\PhpParser\Node $expr, int $depth): string
    {
        if ($depth > 6) {
            return '';
        }

        $next = $depth + 1;

        return match (true) {
            $expr instanceof \PhpParser\Node\Scalar\String_, $expr instanceof \PhpParser\Node\InterpolatedStringPart => $expr->value,
            $expr instanceof \PhpParser\Node\Scalar\InterpolatedString => self::sequencePrefix($expr->parts, $next),
            $expr instanceof \PhpParser\Node\Expr\BinaryOp\Concat => self::sequencePrefix([$expr->left, $expr->right], $next),
            $expr instanceof \PhpParser\Node\Expr\Ternary && $expr->if instanceof \PhpParser\Node\Expr => self::common(self::literalPrefix($expr->if, $next), self::literalPrefix($expr->else, $next)),
            $expr instanceof \PhpParser\Node\Expr\FuncCall && $expr->name instanceof \PhpParser\Node\Name && $expr->name->toLowerString() === 'sprintf'
                && ($expr->args[0] ?? null) instanceof \PhpParser\Node\Arg && $expr->args[0]->value instanceof \PhpParser\Node\Scalar\String_
                => explode('%', $expr->args[0]->value->value, 2)[0],
            $expr instanceof \PhpParser\Node\Expr\Variable && is_string($expr->name) => self::variablePrefix($expr, $next),
            default => '',
        };
    }

    /** @param array<\PhpParser\Node> $parts */
    private static function sequencePrefix(array $parts, int $depth): string
    {
        $result = '';
        foreach ($parts as $part) {
            $prefix = self::literalPrefix($part, $depth);
            $result .= $prefix;
            // Часть известна целиком, только если это литерал
            if (!$part instanceof \PhpParser\Node\Scalar\String_ && !$part instanceof \PhpParser\Node\InterpolatedStringPart) {
                break;
            }
        }

        return $result;
    }

    private static function variablePrefix(\PhpParser\Node\Expr\Variable $var, int $depth): string
    {
        $function = $var->getAttribute('parent');
        while ($function instanceof \PhpParser\Node && !$function instanceof \PhpParser\Node\FunctionLike) {
            $function = $function->getAttribute('parent');
        }

        if (!$function instanceof \PhpParser\Node\FunctionLike) {
            return '';
        }

        $prefix = null;
        foreach (new \PhpParser\NodeFinder()->findInstanceOf($function->getStmts() ?? [], \PhpParser\Node\Expr\Assign::class) as $assign) {
            if ($assign->var instanceof \PhpParser\Node\Expr\Variable && $assign->var->name === $var->name) {
                $value  = self::literalPrefix($assign->expr, $depth);
                $prefix = $prefix === null ? $value : self::common($prefix, $value);
            }
        }

        return $prefix ?? '';
    }

    private static function common(string $a, string $b): string
    {
        $length = 0;
        while ($length < strlen($a) && $length < strlen($b) && $a[$length] === $b[$length]) {
            $length++;
        }

        return substr($a, 0, $length);
    }

    /** Начало полного имени класса: сегменты-идентификаторы через `\`, хотя бы один `\`. */
    private static function namespacePrefix(string $value): bool
    {
        return preg_match('/^\\\\?[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*\\\\[A-Za-z0-9_]*$/', $value) === 1;
    }

    /** @return list<string> код внешних (с семенами — достижимых) файлов */
    public function files(): array
    {
        if ($this->files !== null) {
            return $this->files;
        }

        $external = array_filter($this->fileMap, fn(string $code): bool => !$this->isInternal($code));

        return $this->files = array_values($this->seeds === null ? $external : $this->reachable($external));
    }

    /**
     * Внешние файлы, которые программа может загрузить (см. описание класса).
     *
     * @param  array<string, string> $external
     * @return array<string, string>
     */
    private function reachable(array $external): array
    {
        $byName = [];
        foreach ($external as $key => $code) {
            foreach (self::declaredNames($code) as $fqn) {
                $byName[$fqn][] = $key;
            }
        }

        // Файлы без классов (функции, бутстрапы) Composer подключает вместе с autoload.php
        $autoloaded = $this->autoloadFiles();
        $queue      = !$this->autoloadRoots ? [] : array_keys(array_filter(
            $external,
            static fn(string $code, string $key): bool => self::declaredNames($code) === []
                && ($autoloaded === null ? str_starts_with($key, 'vendor/') : isset($autoloaded[$key])),
            ARRAY_FILTER_USE_BOTH,
        ));
        foreach (array_keys($this->seeds['names'] ?? []) as $name) {
            array_push($queue, ...$byName[(string) $name] ?? []);
        }

        foreach (array_keys($this->seeds['prefixes'] ?? []) as $prefix) {
            foreach ($byName as $fqn => $keys) {
                if (str_starts_with((string) $fqn, (string) $prefix)) {
                    array_push($queue, ...$keys);
                }
            }
        }

        $seen = [];
        while ($queue !== []) {
            $key = array_pop($queue);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            // Служебные файлы Composer — карты «имя → путь»: строки в них класс не загружают
            if (str_starts_with((string) $key, 'vendor/composer/')) {
                continue;
            }

            foreach ($this->referencedNames($external[$key]) as $name) {
                array_push($queue, ...$byName[$name] ?? []);
            }
        }

        return array_intersect_key($external, $seen);
    }

    /** @return list<string> lowercase полные имена классов, объявленных в файле */
    private static function declaredNames(string $code): array
    {
        $namespace = preg_match('/(?:^|<\?php)\s*namespace\s+([\w\\\\]+)\s*[;{]/m', $code, $m) === 1 ? strtolower($m[1]) . '\\' : '';
        preg_match_all('/(?:^|[;{}])\s*(?:(?:final|abstract|readonly)\s+)*(?:class|interface|trait|enum)\s+(\w+)/mi', $code, $found);

        return array_map(static fn(string $short): string => $namespace . strtolower($short), $found[1]);
    }

    /**
     * Полные имена (lowercase) классов, которые файл может загрузить: `new X`, `X::…`,
     * extends/implements, строки с `\`. Имена в `use`, типах и instanceof класс не
     * загружают — они только разрешают короткие имена.
     *
     * @return list<string>
     */
    private function referencedNames(string $code): array
    {
        $namespace = preg_match('/(?:^|<\?php)\s*namespace\s+([\w\\\\]+)\s*[;{]/m', $code, $m) === 1 ? strtolower($m[1]) : '';
        $uses      = [];
        preg_match_all('/(?:^|[;{])\s*use\s+([\w\\\\]+)(?:\s+as\s+(\w+))?\s*;/mi', $code, $useMatches, PREG_SET_ORDER);
        foreach ($useMatches as $use) {
            $fqn          = strtolower(ltrim($use[1], '\\'));
            $alias        = strtolower(($use[2] ?? '') !== '' ? $use[2] : substr((string) strrchr('\\' . $fqn, '\\'), 1));
            $uses[$alias] = $fqn;
        }

        $resolve = static function (string $name) use ($namespace, $uses): string {
            if (str_starts_with($name, '\\')) {
                return strtolower(ltrim($name, '\\'));
            }

            $lower = strtolower($name);
            $first = explode('\\', $lower)[0];

            return isset($uses[$first]) ? $uses[$first] . substr($lower, strlen($first)) : ($namespace !== '' ? $namespace . '\\' : '') . $lower;
        };

        $names   = [];
        $tokens  = array_values(array_filter(token_get_all($code), static fn(mixed $t): bool => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
        $listing = false; // внутри extends/implements-списка
        foreach ($tokens as $i => $token) {
            if (!is_array($token)) {
                $listing = $listing && $token === ',';
                continue;
            }

            if ($token[0] === T_NAMESPACE) {
                continue;
            }

            if ($token[0] === T_EXTENDS || $token[0] === T_IMPLEMENTS) {
                $listing = true;
                continue;
            }

            if ($token[0] === T_CONSTANT_ENCAPSED_STRING && str_contains($token[1], '\\')) {
                $names[] = strtolower(ltrim(stripslashes(substr($token[1], 1, -1)), '\\'));
                continue;
            }

            if (!in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                $listing = false;
                continue;
            }

            $previous = $tokens[$i - 1] ?? null;
            $next     = $tokens[$i + 1] ?? null;
            $loads    = $listing
                || is_array($previous) && $previous[0] === T_NEW
                || is_array($next) && $next[0] === T_DOUBLE_COLON;
            if ($loads && !in_array(strtolower($token[1]), ['self', 'static', 'parent'], true)) {
                $names[] = $resolve($token[1]);
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Идентификаторы (lowercase), которые упоминает внешний код: имена после `->`, `?->`,
     * `::`, вызовы `name(`, строки (`'m'`, хвост `'C::m'`).
     *
     * @return array<string, true>
     */
    public function names(): array
    {
        $names = [];
        foreach ($this->files() as $code) {
            $names += $this->parse($code)['names'];
        }

        return $names;
    }

    /**
     * Имена методов и функций (lowercase), объявленных во внешнем коде: внешний наследник
     * может переопределить метод программы.
     *
     * @return array<string, true>
     */
    public function declaredFunctions(): array
    {
        $names = [];
        foreach ($this->files() as $code) {
            $names += $this->parse($code)['declared'];
        }

        return $names;
    }

    /**
     * Наследует или реализует ли внешний код класс программы (`Foo_Bar` — плоское имя
     * `Foo\Bar`). Имена в extends/implements разрешаются по namespace и use файла.
     */
    public function extendsOrImplements(string $flatName): bool
    {
        if ($this->parents === null) {
            $this->parents = [];
            foreach ($this->files() as $code) {
                $this->parents += $this->parentsOf($code);
            }
        }

        return isset($this->parents[strtolower($flatName)]);
    }

    /**
     * Плоские имена (lowercase) всех классов из extends/implements файла.
     *
     * @return array<string, true>
     */
    private function parentsOf(string $code): array
    {
        $namespace = preg_match('/(?:^|<\?php)\s*namespace\s+([\w\\\\]+)\s*[;{]/m', $code, $match) === 1 ? $match[1] : '';
        $uses      = [];
        preg_match_all('/(?:^|[;{])\s*use\s+([\w\\\\]+)(?:\s+as\s+(\w+))?\s*;/mi', $code, $useMatches, PREG_SET_ORDER);
        foreach ($useMatches as $use) {
            $alias                     = $use[2] ?? '';
            $uses[strtolower($alias !== '' ? $alias : substr((string) strrchr('\\' . $use[1], '\\'), 1))] = ltrim($use[1], '\\');
        }

        $result = [];
        preg_match_all('/\b(?:extends|implements)\s+([\w\\\\\s,]+?)\s*(?=\{|\bimplements\b)/i', $code, $clauses);
        foreach ($clauses[1] as $clause) {
            foreach (preg_split('/\s*,\s*/', trim($clause)) ?: [] as $name) {
                if ($name === '') {
                    continue;
                }

                $first = strtolower(explode('\\', ltrim($name, '\\'))[0]);
                $fqn   = match (true) {
                    str_starts_with($name, '\\') => ltrim($name, '\\'),
                    isset($uses[$first]) => $uses[$first] . substr($name, strlen($first)),
                    default => ($namespace !== '' ? $namespace . '\\' : '') . $name,
                };
                $result[strtolower(str_replace('\\', '_', $fqn))] = true;
            }
        }

        return $result;
    }

    /**
     * Файлы, которые Composer подключает вместе с autoload.php (autoload_files.php);
     * null — списка нет в карте файлов. Прочие файлы без классов (бутстрап и точка
     * входа проекта, конфиги) выполняются, только если их подключили, — а подключённые
     * уже встроены в программу.
     *
     * @return array<string, true>|null
     */
    private function autoloadFiles(): ?array
    {
        $list = $this->fileMap['vendor/composer/autoload_files.php'] ?? null;
        if ($list === null) {
            return null;
        }

        preg_match_all('/\$(vendorDir|baseDir)\s*\.\s*\'([^\']+)\'/', $list, $matches, PREG_SET_ORDER);
        $result = [];
        foreach ($matches as [, $base, $path]) {
            $result[$base === 'vendorDir' ? 'vendor' . $path : ltrim($path, '/')] = true;
        }

        return $result;
    }

    private function isInternal(string $code): bool
    {
        $namespace = preg_match('/(?:^|<\?php)\s*namespace\s+([\w\\\\]+)\s*[;{]/m', $code, $match) === 1 ? $match[1] . '\\' : '';
        if (preg_match_all('/(?:^|[;{}])\s*(?:(?:final|abstract|readonly)\s+)*(?:class|interface|trait|enum)\s+(\w+)/m', $code, $matches) < 1) {
            return false;
        }

        return array_any($matches[1], fn(string $short): bool => isset($this->classes[strtolower(str_replace('\\', '_', $namespace . $short))]));
    }

    /** @return array{names: array<string, true>, declared: array<string, true>} */
    private function parse(string $code): array
    {
        $key = sha1($code);
        if (isset(self::$parsed[$key])) {
            return self::$parsed[$key];
        }

        $names    = [];
        $declared = [];
        $prev     = null;
        foreach (token_get_all($code) as $token) {
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            if (is_array($token) && $token[0] === T_STRING) {
                $afterAccess = is_array($prev) && in_array($prev[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true);
                if (is_array($prev) && $prev[0] === T_FUNCTION) {
                    $declared[strtolower($token[1])] = true;
                } elseif ($afterAccess) {
                    $names[strtolower($token[1])] = true;
                }
            } elseif (is_array($token) && in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED], true)) {
                $names[strtolower(ltrim((string) strrchr('\\' . $token[1], '\\'), '\\'))] = true;
            } elseif (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                $value         = strtolower(substr($token[1], 1, -1));
                $names[$value] = true;
                $tail          = strrchr($value, ':');
                if ($tail !== false) {
                    $names[substr($tail, 1)] = true;
                }
            } elseif ($token === '(' && is_array($prev) && in_array($prev[0], [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED], true)) {
                // вызов функции: name( / \ns\name(
                $names[strtolower(ltrim((string) strrchr('\\' . $prev[1], '\\'), '\\'))] = true;
            }

            $prev = $token;
        }

        return self::$parsed[$key] = ['names' => $names, 'declared' => $declared];
    }
}
