<?php

declare(strict_types=1);

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NodeConnectingVisitor;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as Printer;
use Recombinator\Transformation\Visitor\FileMapIncludeVisitor;
use Recombinator\Transformation\Visitor\SymfonyRuntimeVisitor;

/** Вход Symfony: public/index.php подключает vendor/autoload_runtime.php и возвращает замыкание. */
function applySymfonyRuntime(string $runtime): string
{
    $fileMap = [
        'public/index.php'            => "<?php\nrequire_once dirname(__DIR__).'/vendor/autoload_runtime.php';\nreturn function (array \$context) { return new Kernel(\$context['APP_ENV']); };\n",
        'vendor/autoload_runtime.php' => $runtime,
        'vendor/autoload.php'         => "<?php\nrequire_once __DIR__ . '/composer/autoload_real.php';\nreturn ComposerAutoloaderInit::getLoader();\n",
    ];
    $ast = new ParserFactory()->createForNewestSupportedVersion()->parse($fileMap['public/index.php']) ?? [];
    foreach ([new FileMapIncludeVisitor($fileMap, 'public/index.php'), new SymfonyRuntimeVisitor()] as $visitor) {
        $ast = new NodeTraverser(new NodeConnectingVisitor())->traverse($ast);
        $ast = new NodeTraverser($visitor)->traverse($ast);
    }

    return new Printer()->prettyPrint($ast);
}

it('replaces the second require of the entry file with its closure and hoists the autoloader', function (): void {
    $result = applySymfonyRuntime(<<<'PHP'
        <?php
        if (true === (require_once __DIR__.'/autoload.php') || empty($_SERVER['SCRIPT_FILENAME'])) {
            return;
        }
        $app = require $_SERVER['SCRIPT_FILENAME'];
        exit((new Runtime())->run($app));
        PHP);

    expect($result)
        ->toContain("require_once __DIR__ . '/autoload.php';\nif (false || empty(\$_SERVER['SCRIPT_FILENAME'])) {")
        ->toContain("\$app = function (array \$context) {\n        return new Kernel(\$context['APP_ENV']);\n    };")
        ->not->toContain('require $_SERVER');
});

it('leaves other uses of SCRIPT_FILENAME and the autoloader alone', function (): void {
    $result = applySymfonyRuntime(<<<'PHP'
        <?php
        $loaded = require_once __DIR__.'/autoload.php';
        $file = $_SERVER['SCRIPT_FILENAME'];
        PHP);

    expect($result)->toContain("\$loaded = require_once __DIR__ . '/autoload.php';")->toContain("\$file = \$_SERVER['SCRIPT_FILENAME'];");
});
