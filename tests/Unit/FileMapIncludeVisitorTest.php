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

    return (new Printer())->prettyPrint($ast);
}

it('inlines plain string require', function (): void {
    $result = parseAndApply(
        '<?php require "helper.php"; echo $x;',
        ['helper.php' => '<?php $x = 42;'],
    );
    expect($result)->toContain('$x = 42');
});

it('inlines __DIR__ . \'/file.php\' require', function (): void {
    $result = parseAndApply(
        '<?php require __DIR__ . \'/helper.php\'; echo $x;',
        ['helper.php' => '<?php $x = 1;'],
    );
    expect($result)->toContain('$x = 1');
});

it('inlines dirname(__DIR__) . \'/file.php\' require', function (): void {
    $result = parseAndApply(
        '<?php require_once dirname(__DIR__) . \'/lib/helper.php\'; echo $x;',
        ['lib/helper.php' => '<?php $x = 99;'],
    );
    expect($result)->toContain('$x = 99');
});

it('inlines dirname(dirname(__DIR__)) . \'/file.php\' require', function (): void {
    $result = parseAndApply(
        '<?php require dirname(dirname(__DIR__)) . \'/deep/helper.php\'; echo $x;',
        ['deep/helper.php' => '<?php $x = 7;'],
    );
    expect($result)->toContain('$x = 7');
});

it('falls back to basename lookup', function (): void {
    $result = parseAndApply(
        '<?php require __DIR__ . \'/sub/dir/helper.php\';',
        ['helper.php' => '<?php $x = 5;'],
    );
    expect($result)->toContain('$x = 5');
});

it('resolves dirname(__DIR__) using current file context', function (): void {
    // public/index.php does: require_once dirname(__DIR__) . '/vendor/autoload_runtime.php'
    // dirname(__DIR__) from public/index.php → project root → vendor/autoload_runtime.php
    $result = parseAndApply(
        '<?php require_once dirname(__DIR__) . \'/vendor/autoload_runtime.php\';',
        ['vendor/autoload_runtime.php' => '<?php $booted = true;'],
        'public/index.php',
    );
    expect($result)->toContain('$booted = true');
});

it('resolves __DIR__ with subdirectory path using current file context', function (): void {
    // src/Controller/BlogController.php does: require __DIR__ . '/../Repository/PostRepository.php'
    // → src/Repository/PostRepository.php
    $result = parseAndApply(
        '<?php require __DIR__ . \'/../Repository/PostRepository.php\';',
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
        'public/index.php'            => '<?php require_once dirname(__DIR__) . \'/vendor/autoload_runtime.php\';',
        'vendor/autoload_runtime.php' => '<?php require_once __DIR__ . \'/autoload.php\'; $booted = true;',
        'vendor/autoload.php'         => '<?php $loaded = true;',
    ];

    $parser  = new ParserFactory()->createForNewestSupportedVersion();
    $printer = new Printer();
    $ast     = $parser->parse($fileMap['public/index.php']) ?? [];

    $applyPass = static function (array &$ast, array $fileMap, string $currentFile) use ($printer): void {
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
