<?php

declare(strict_types=1);

namespace Recombinator\Support;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

/**
 * Срез проекта по покрытию: исходный проект выполняется на запросах из описания сервера
 * (`profile`), и в карте файлов остаётся только то, что выполнилось:
 * - файлы, которые ни один запрос не подключил, убираются;
 * - тело функции или метода, ни одна строка которого не выполнилась, заменяется
 *   исключением — программа годится только для таких запросов, иначе падает явно;
 * - классы, загруженные во время выполнения, возвращаются списками: их называют данные
 *   (config/bundles.php, кэш контейнера), конвейер должен их встроить и не удалять.
 *
 * Включается допущением `onlyProfiledCode`: большой проект (Symfony) целиком не
 * помещается в развёртку, а выполняемая на запросах часть — небольшая. Покрытие
 * снимает Xdebug (`-d xdebug.mode=coverage`); без него карта файлов не меняется.
 * Результат кэшируется в var/cache/probes по входу и запросам.
 */
final class CoverageProbe
{
    public const string NOT_PROFILED = 'Recombinator: code path not covered by the request profile';

    /**
     * @param  array<string, string>        $fileMap  путь от корня проекта → код
     * @param  list<array<string, string>>  $requests method, uri
     * @return array{array<string, string>, RuntimeProfile} карта файлов и классы, известные по выполнению
     */
    public static function prune(string $projectDir, string $entry, array $fileMap, array $requests, string $cacheKey, string $cacheDir): array
    {
        $coverage = self::coverage($projectDir, $entry, $requests, $cacheKey, $cacheDir);
        if ($coverage === null) {
            return [$fileMap, new RuntimeProfile()];
        }

        $parser  = new ParserFactory()->createForNewestSupportedVersion();
        $printer = new Standard();
        $pruned  = [];
        foreach ($fileMap as $path => $code) {
            // Данные Composer (autoload_files.php) читает сам конвейер, а не программа
            if (str_contains($path, 'vendor/composer/')) {
                $pruned[$path] = $code;
                continue;
            }

            $lines = $coverage['files'][$path] ?? null;
            if ($lines === null) {
                continue; // ни один запрос файл не подключил
            }

            try {
                $ast = $parser->parse($code) ?? [];
            } catch (\PhpParser\Error) {
                $pruned[$path] = $code;
                continue;
            }

            $pruned[$path] = self::stubUnexecuted($ast, array_fill_keys($lines, true)) ? $printer->prettyPrintFile($ast) : $code;
        }

        // Входной файл выполняется всегда
        $pruned[$entry] = $fileMap[$entry] ?? '';

        return [$pruned, new RuntimeProfile($coverage['classes'], $coverage['types'], $coverage['extended'], $coverage['included'])];
    }

    /**
     * Заменяет тела невыполненных функций и методов исключением.
     *
     * @param  array<Node>       $ast
     * @param  array<int, true>  $executed выполненные строки
     * @return bool              что-то заменено
     */
    public static function stubUnexecuted(array $ast, array $executed): bool
    {
        $changed = false;
        foreach (new NodeFinder()->findInstanceOf($ast, Node\FunctionLike::class) as $function) {
            // Деструктор выполняется после сбора покрытия (объекты уничтожаются после shutdown-функций)
            if (
                !$function instanceof Node\Stmt\Function_ && !$function instanceof Node\Stmt\ClassMethod || $function->stmts === null || $function->stmts === []
                || $function instanceof Node\Stmt\ClassMethod && $function->name->toLowerString() === '__destruct'
            ) {
                continue;
            }

            $from = $function->stmts[0]->getStartLine();
            $to   = $function->getEndLine();
            if (array_any(range($from, $to), static fn(int $line): bool => isset($executed[$line]))) {
                continue;
            }

            $function->stmts = [new Node\Stmt\Expression(new Node\Expr\Throw_(new Node\Expr\New_(
                new Node\Name\FullyQualified('LogicException'),
                [new Node\Arg(new Node\Scalar\String_(self::NOT_PROFILED))],
            )))];
            $changed = true;
        }

        return $changed;
    }

    /**
     * Выполненные строки по файлам проекта (путь от корня → номера строк; подключённый
     * файл без выполненных строк — интерфейс — пустой список) и классы проекта, загруженные
     * хотя бы одним запросом. null — покрытие не снять.
     *
     * @param  list<array<string, string>> $requests
     * @return array{files: array<string, list<int>>, classes: list<string>, types: list<string>, extended: list<string>, included: list<string>}|null
     */
    private static function coverage(string $projectDir, string $entry, array $requests, string $cacheKey, string $cacheDir): ?array
    {
        // Ключ — и по обёртке: её правка меняет собираемые данные
        $file = $cacheDir . '/coverage-' . sha1($cacheKey . "\0" . json_encode($requests) . "\0" . self::script($projectDir, $projectDir . '/' . $entry)) . '.json';
        if (is_file($file)) {
            $cached = json_decode((string) file_get_contents($file), true);
            if (is_array($cached) && is_array($cached['files'] ?? null) && is_array($cached['classes'] ?? null) && is_array($cached['types'] ?? null) && is_array($cached['extended'] ?? null) && is_array($cached['included'] ?? null)) {
                /** @var array{files: array<string, list<int>>, classes: list<string>, types: list<string>, extended: list<string>, included: list<string>} $cached */
                return $cached;
            }
        }

        $script = tempnam(sys_get_temp_dir(), 'rcbcov');
        $out    = tempnam(sys_get_temp_dir(), 'rcbcovout');
        if ($script === false || $out === false) {
            return null;
        }

        $entryPath = rtrim($projectDir, '/') . '/' . $entry;
        file_put_contents($script, self::script($projectDir, $entryPath));
        $merged  = [];
        $classes = [];
        $types   = [];
        $extended = [];
        $included = [];
        try {
            foreach ($requests as $request) {
                @unlink($out);
                $env = sprintf(
                    'RCB_COVERAGE_OUT=%s RCB_METHOD=%s RCB_URI=%s',
                    escapeshellarg($out),
                    escapeshellarg($request['method'] ?? 'GET'),
                    escapeshellarg($request['uri'] ?? '/'),
                );
                exec('cd ' . escapeshellarg(dirname($entryPath)) . ' && ' . $env . ' timeout 120 ' . escapeshellarg(PHP_BINARY)
                    . ' -d xdebug.mode=coverage -d memory_limit=1G ' . escapeshellarg($script) . ' > /dev/null 2>&1');
                $data = is_file($out) ? json_decode((string) file_get_contents($out), true) : null;
                if (!is_array($data) || !is_array($data['files'] ?? null) || !is_array($data['classes'] ?? null) || !is_array($data['types'] ?? null) || !is_array($data['extended'] ?? null) || !is_array($data['included'] ?? null)) {
                    return null; // нет Xdebug или запрос не выполнился
                }

                $classes = [...$classes, ...array_values(array_filter($data['classes'], is_string(...)))];
                $types   = [...$types, ...array_values(array_filter($data['types'], is_string(...)))];
                $extended = [...$extended, ...array_values(array_filter($data['extended'], is_string(...)))];
                $included = [...$included, ...array_values(array_filter($data['included'], is_string(...)))];
                foreach ($data['files'] as $path => $lines) {
                    $relative = str_starts_with((string) $path, rtrim($projectDir, '/') . '/') ? substr((string) $path, strlen(rtrim($projectDir, '/')) + 1) : null;
                    if ($relative !== null && is_array($lines)) {
                        $merged[$relative] = array_values(array_unique([...$merged[$relative] ?? [], ...array_map(static fn(mixed $line): int => is_numeric($line) ? (int) $line : 0, $lines)]));
                    }
                }
            }
        } finally {
            @unlink($script);
            @unlink($out);
        }

        $result = ['files' => $merged, 'classes' => array_values(array_unique($classes)), 'types' => array_values(array_unique($types)), 'extended' => array_values(array_unique($extended)), 'included' => array_values(array_unique($included))];
        @mkdir($cacheDir, 0777, true);
        file_put_contents($file, json_encode($result));

        return $result;
    }

    /** Обёртка запроса: окружение запроса, покрытие, вход проекта. */
    private static function script(string $projectDir, string $entryPath): string
    {
        $entry   = var_export($entryPath, true);
        // Классы из var/ (кэш контейнера) подключает сам код require-ом, не автозагрузка: встроенная
        // копия столкнулась бы с объявлением из файла
        $runtime = var_export(rtrim($projectDir, '/') . '/var/', true);

        return <<<PHP
            <?php
            if (!function_exists('xdebug_start_code_coverage')) {
                exit(1);
            }

            \$_SERVER['REQUEST_METHOD'] = (string) getenv('RCB_METHOD');
            \$_SERVER['REQUEST_URI'] = (string) getenv('RCB_URI');
            \$_SERVER['SCRIPT_FILENAME'] = {$entry};
            \$_SERVER['SCRIPT_NAME'] = '/' . basename({$entry});
            \$_SERVER['PHP_SELF'] = \$_SERVER['SCRIPT_NAME'];
            \$_SERVER['HTTP_HOST'] ??= 'localhost';
            // Сбор — последним: shutdown-функции приложения тоже выполняют код
            register_shutdown_function(static fn() => register_shutdown_function(static function (): void {
                \$result = [];
                foreach (xdebug_get_code_coverage() as \$path => \$lines) {
                    \$result[\$path] = array_keys(array_filter(\$lines, static fn(int \$hits): bool => \$hits > 0));
                }

                // Подключённый файл без выполненных строк (интерфейс, класс только с константами) тоже нужен
                foreach (get_included_files() as \$path) {
                    \$result[\$path] ??= [];
                }

                // Классы проекта, созданные или загруженные запросом: конкретные — их создают по имени
                // из данных. Трейты, интерфейсы и абстрактные классы правила встраивают и снимают,
                // и встраивание их по имени спорило бы с этими правилами
                \$classes = array_filter(get_declared_classes(), static function (string \$class): bool {
                    \$reflection = new ReflectionClass(\$class);

                    return !\$reflection->isInternal() && !\$reflection->isAbstract() && !\$reflection->isAnonymous()
                        && !str_starts_with((string) \$reflection->getFileName(), {$runtime});
                });
                // Все загруженные типы (и трейты, абстрактные): их методы вызывает и код, подключаемый
                // во время выполнения (кэш контейнера), — удалять их нельзя
                \$types = array_filter(
                    [...get_declared_classes(), ...get_declared_interfaces(), ...get_declared_traits()],
                    static fn(string \$type): bool => !new ReflectionClass(\$type)->isInternal() && !new ReflectionClass(\$type)->isAnonymous(),
                );
                // Классы из var/ (кэш контейнера), их предки (у них есть наследник вне программы)
                \$extended = [];
                \$included = [];
                foreach (get_declared_classes() as \$class) {
                    if (str_starts_with((string) new ReflectionClass(\$class)->getFileName(), {$runtime})) {
                        \$included[] = \$class;
                        \$extended = [...\$extended, ...array_values(class_parents(\$class)), ...array_values(class_implements(\$class))];
                    }
                }

                file_put_contents((string) getenv('RCB_COVERAGE_OUT'), json_encode(['files' => \$result, 'classes' => array_values(\$classes), 'types' => array_values(\$types), 'extended' => array_values(array_unique(\$extended)), 'included' => \$included]));
            }));
            xdebug_start_code_coverage();
            require {$entry};
            PHP;
    }
}
