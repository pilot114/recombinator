<?php

declare(strict_types=1);

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NodeConnectingVisitor;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as Printer;
use Recombinator\Transformation\Visitor\FileMapIncludeVisitor;

function parseAndApply(string $code, array $fileMap, string $currentFile = ''): string
{
    $parser = new ParserFactory()->createForNewestSupportedVersion();
    $ast    = $parser->parse($code) ?? [];

    $t1 = new NodeTraverser();
    $t1->addVisitor(new NodeConnectingVisitor());

    $ast = $t1->traverse($ast);

    $t2 = new NodeTraverser();
    $t2->addVisitor(new FileMapIncludeVisitor($fileMap, $currentFile));

    $ast = $t2->traverse($ast);

    return new Printer()->prettyPrint($ast);
}

it('inlines plain string require', function (): void {
    $result = parseAndApply(
        '<?php require "helper.php"; echo $x;',
        ['helper.php' => '<?php $x = 42;'],
    );
    expect($result)->toContain('$x = 42');
});

it("inlines __DIR__ . '/file.php' require", function (): void {
    $result = parseAndApply(
        '<?php require __DIR__ . \'/helper.php\'; echo $x;',
        ['helper.php' => '<?php $x = 1;'],
    );
    expect($result)->toContain('$x = 1');
});

it("inlines dirname(__DIR__) . '/file.php' require", function (): void {
    $result = parseAndApply(
        '<?php require_once dirname(__DIR__) . \'/lib/helper.php\'; echo $x;',
        ['lib/helper.php' => '<?php $x = 99;'],
    );
    expect($result)->toContain('$x = 99');
});

it("inlines dirname(dirname(__DIR__)) . '/file.php' require", function (): void {
    $result = parseAndApply(
        '<?php require dirname(dirname(__DIR__)) . \'/deep/helper.php\'; echo $x;',
        ['deep/helper.php' => '<?php $x = 7;'],
    );
    expect($result)->toContain('$x = 7');
});

it('falls back to basename lookup', function (): void {
    $result = parseAndApply(
        "<?php require __DIR__ . '/sub/dir/helper.php';",
        ['helper.php' => '<?php $x = 5;'],
    );
    expect($result)->toContain('$x = 5');
});

it('resolves dirname(__DIR__) using current file context', function (): void {
    // public/index.php does: require_once dirname(__DIR__) . '/vendor/autoload_runtime.php'
    // dirname(__DIR__) from public/index.php → project root → vendor/autoload_runtime.php
    $result = parseAndApply(
        "<?php require_once dirname(__DIR__) . '/vendor/autoload_runtime.php';",
        ['vendor/autoload_runtime.php' => '<?php $booted = true;'],
        'public/index.php',
    );
    expect($result)->toContain('$booted = true');
});

it('resolves __DIR__ with subdirectory path using current file context', function (): void {
    // src/Controller/BlogController.php does: require __DIR__ . '/../Repository/PostRepository.php'
    // → src/Repository/PostRepository.php
    $result = parseAndApply(
        "<?php require __DIR__ . '/../Repository/PostRepository.php';",
        ['src/Repository/PostRepository.php' => '<?php $repo = true;'],
        'src/Controller/BlogController.php',
    );
    expect($result)->toContain('$repo = true');
});

it('propagates source file context transitively', function (): void {
    // public/index.php → requires vendor/autoload_runtime.php
    // vendor/autoload_runtime.php → requires __DIR__ . '/autoload.php' (= vendor/autoload.php)
    // Атрибуты AST-узлов живут между проходами в памяти — имитируем это
    // двумя traversal-проходами на одном и том же $ast без ре-парсинга.
    $fileMap = [
        'public/index.php'            => "<?php require_once dirname(__DIR__) . '/vendor/autoload_runtime.php';",
        'vendor/autoload_runtime.php' => '<?php require_once __DIR__ . \'/autoload.php\'; $booted = true;',
        'vendor/autoload.php'         => '<?php $loaded = true;',
    ];

    $parser  = new ParserFactory()->createForNewestSupportedVersion();
    $printer = new Printer();
    $ast     = $parser->parse($fileMap['public/index.php']) ?? [];

    $applyPass = static function (array &$ast, array $fileMap, string $currentFile): void {
        $t1 = new NodeTraverser();
        $t1->addVisitor(new NodeConnectingVisitor());

        $ast = array_values($t1->traverse($ast));
        $t2 = new NodeTraverser();
        $t2->addVisitor(new FileMapIncludeVisitor($fileMap, $currentFile));

        $ast = array_values($t2->traverse($ast));
    };

    // Проход 1: инлайним vendor/autoload_runtime.php; его include-узлы получают sourceFile=vendor/autoload_runtime.php
    $applyPass($ast, $fileMap, 'public/index.php');
    expect($printer->prettyPrint($ast))->toContain('$booted = true');

    // Проход 2: инлайним vendor/autoload.php, используя сохранённый sourceFile
    $applyPass($ast, $fileMap, 'public/index.php');
    expect($printer->prettyPrint($ast))->toContain('$loaded = true');
});

it('skips unresolvable require', function (): void {
    $result = parseAndApply(
        '<?php require $dynamic; echo 1;',
        [],
    );
    expect($result)->toContain('echo 1');
});

it('inlines require used as a value: file body before statement, return value in place', function (): void {
    $result = parseAndApply(
        '<?php $cfg = require __DIR__ . "/config.php"; echo $cfg;',
        ['config.php' => '<?php $base = 1; return ["a" => $base];'],
    );
    expect($result)
        ->toContain('$base = 1;')
        ->toContain('$cfg = ["a" => $base];')
        ->not->toContain('require')
        ->not->toContain('return');
});

it('inlines require passed as first argument', function (): void {
    $result = parseAndApply(
        '<?php return new Container(require "settings.php");',
        ['settings.php' => '<?php return ["x" => 1];'],
    );
    expect($result)->toContain('return new Container(["x" => 1]);');
});

it('does not inline value require when it is not evaluated first', function (): void {
    $result = parseAndApply(
        '<?php $x = foo(bar(), require "settings.php");',
        ['settings.php' => '<?php return 1;'],
    );
    expect($result)->toContain('require');
});

it('does not inline value require when file has early return', function (): void {
    $result = parseAndApply(
        '<?php $x = require "settings.php";',
        ['settings.php' => '<?php if ($a) { return 1; } return 2;'],
    );
    expect($result)->toContain('require');
});

it('drops trailing return of statement-level require so it does not end the script', function (): void {
    $result = parseAndApply(
        '<?php require "autoload.php"; echo 1;',
        ['autoload.php' => '<?php $loaded = true; return Loader::get();'],
    );
    expect($result)
        ->toContain('$loaded = true;')
        ->toContain('Loader::get();')
        ->not->toContain('return');
});

it('does not inline a file that declares a namespace', function (): void {
    $result = parseAndApply(
        '<?php function load() { require __DIR__ . "/Loader.php"; }',
        ['Loader.php' => "<?php\nnamespace Composer\\Autoload;\nclass ClassLoader {}"],
    );
    expect($result)->toContain('require __DIR__ . "/Loader.php";')->not->toContain('namespace');
});

it('drops declare(strict_types) of the included file', function (): void {
    $result = parseAndApply(
        '<?php echo 1; require "settings.php";',
        ['settings.php' => "<?php\ndeclare(strict_types=1);\n\$x = 1;"],
    );
    expect($result)->toContain('$x = 1;')->not->toContain('declare');
});

it('replaces the Composer autoloader with its autoload files', function (): void {
    $files = <<<'PHP'
<?php
$vendorDir = dirname(__DIR__);
$baseDir = dirname($vendorDir);
return array(
    'abc' => $vendorDir . '/symfony/polyfill/bootstrap.php',
    'def' => $baseDir . '/src/helpers.php',
);
PHP;
    $result = parseAndApply(
        "<?php require __DIR__ . '/../vendor/autoload.php'; echo 1;",
        [
            'public/index.php'                  => '',
            'vendor/autoload.php'               => "<?php require_once __DIR__ . '/composer/autoload_real.php';"
                . ' return ComposerAutoloaderInitX::getLoader();',
            'vendor/composer/autoload_real.php' => '<?php class ComposerAutoloaderInitX {}',
            'vendor/composer/autoload_files.php' => $files,
        ],
        'public/index.php',
    );

    expect($result)
        ->toContain("require_once __DIR__ . '/../vendor/symfony/polyfill/bootstrap.php';")
        ->toContain("require_once __DIR__ . '/../src/helpers.php';")
        ->not->toContain('ComposerAutoloaderInit');
});

it('keeps only an alias loader and Composer as a fallback for runtime class names', function (): void {
    $result = parseAndApply(
        "<?php require __DIR__ . '/vendor/autoload.php'; echo 1;",
        [
            'vendor/autoload.php'               => '<?php return ComposerAutoloaderInitX::getLoader();',
            'vendor/composer/autoload_real.php' => '<?php class ComposerAutoloaderInitX {}',
        ],
        'index.php',
    );

    expect($result)
        ->toContain("class_alias(\$flat, \$class);")
        ->toContain("require_once __DIR__ . '/vendor/autoload.php';")
        ->not->toContain('ComposerAutoloaderInit');
});

it('relocates __DIR__ of a file inlined from another directory', function (): void {
    $result = parseAndApply(
        "<?php require __DIR__ . '/../bootstrap.php';",
        [
            'public/index.php' => '',
            'bootstrap.php'    => '<?php const ROOT = __DIR__; $f = __FILE__; $s = __DIR__ . "/lib/x.php"; require __DIR__ . "/missing.php";',
            'lib/x.php'        => '',
        ],
        'public/index.php',
    );

    expect($result)
        ->toContain("const ROOT = __DIR__ . '/..';")
        ->toContain("\$f = __DIR__ . '/..' . '/bootstrap.php';")
        ->toContain('require __DIR__ . "/missing.php";');
});

it('inlines a namespaced file with functions only', function (): void {
    $functions = <<<'PHP'
<?php
namespace FastRoute;
use Other\Thing;
if (!function_exists('FastRoute\simpleDispatcher')) {
    function simpleDispatcher(callable $cb) { return cachedDispatcher($cb, new Thing(), strlen('x')); }
    function cachedDispatcher(callable $cb) { return new RouteCollector(); }
}
PHP;
    $result = parseAndApply('<?php require __DIR__ . "/functions.php";', ['functions.php' => $functions], 'index.php');

    expect($result)
        ->toContain('function FastRoute_simpleDispatcher(callable $cb)')
        ->toContain('return \FastRoute_cachedDispatcher($cb, new \Other\Thing(), strlen(\'x\'));')
        ->toContain('return new \FastRoute\RouteCollector();')
        ->not->toContain('namespace');
});

it('inlines files with early returns as branches', function (): void {
    $result = parseAndApply(
        "<?php require 'boot.php'; echo f();",
        [
            'boot.php' => "<?php if (\\PHP_VERSION_ID >= 80000) { return require 'boot80.php'; }\nif (!defined('X')) { define('X', 1); }\nfunction f() { return 1; }",
            'boot80.php' => "<?php if (!function_exists('g')) { function g() { return 2; } }",
        ],
    );

    expect($result)->toContain('function f()')
        ->toContain('if (\PHP_VERSION_ID >= 80000) {')
        ->toContain("} else if (!defined('X')) {")
        ->not->toContain('return require');
});
