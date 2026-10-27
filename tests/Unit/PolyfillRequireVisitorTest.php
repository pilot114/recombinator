<?php

declare(strict_types=1);

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NodeConnectingVisitor;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as Printer;
use Recombinator\Core\TargetEnvironment;
use Recombinator\Transformation\Visitor\PolyfillRequireVisitor;

const POLYFILLS = [
    'vendor/poly/mb/bootstrap.php'   => "<?php\nuse X as p;\nif (\\PHP_VERSION_ID >= 80000) {\n    return require __DIR__.'/bootstrap80.php';\n}\nfunction mb_old() {}",
    'vendor/poly/mb/bootstrap80.php' => "<?php\nif (!function_exists('mb_strlen')) { function mb_strlen(\$s) { return 1; } }\nif (!function_exists('mb_trim')) { function mb_trim(\$s) { return \$s; } }",
    'vendor/poly/php84/bootstrap.php' => "<?php\nif (\\PHP_VERSION_ID >= 80400) {\n    return;\n}\nfunction array_find() {}",
    'vendor/poly/php99/bootstrap.php' => "<?php\nif (\\PHP_VERSION_ID >= 99000) {\n    return;\n}\nif (!function_exists('future_fn')) { function future_fn() {} }",
];

/** @param array<string, mixed> $environment */
function applyPolyfill(array $environment): string
{
    $code = "<?php\nrequire_once __DIR__ . '/../vendor/poly/mb/bootstrap.php';\nrequire_once __DIR__ . '/../vendor/poly/php84/bootstrap.php';\n"
        . "require_once __DIR__ . '/../vendor/poly/php99/bootstrap.php';\nrequire_once __DIR__ . '/../vendor/autoload.php';";
    $ast  = new ParserFactory()->createForNewestSupportedVersion()->parse($code) ?? [];
    $ast  = new NodeTraverser(new NodeConnectingVisitor())->traverse($ast);
    $ast  = new NodeTraverser(new PolyfillRequireVisitor(TargetEnvironment::fromArray($environment), POLYFILLS, 'public/index.php'))->traverse($ast);

    return new Printer()->prettyPrint($ast);
}

it('drops polyfill bootstraps that do nothing on the target server', function (): void {
    $result = applyPolyfill(['php' => PHP_VERSION, 'extensions' => ['Core', 'standard', 'mbstring']]);

    expect($result)->not->toContain('poly/mb/')
        ->not->toContain('poly/php84/')
        ->toContain('poly/php99/bootstrap.php')
        ->toContain('vendor/autoload.php');
})->skip(!extension_loaded('mbstring'), 'нужен mbstring у анализатора');

it('keeps bootstraps when the extension is absent or nothing is assumed', function (array $environment): void {
    expect(applyPolyfill($environment))->toContain('poly/mb/bootstrap.php');
})->with([
    'no mbstring' => [['php' => PHP_VERSION, 'extensions' => ['Core', 'standard']]],
    'no assumptions' => [[]],
]);
