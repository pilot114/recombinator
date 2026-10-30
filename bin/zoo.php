#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as Printer;
use Recombinator\Core\ExecutionCache;
use Recombinator\Support\ColorDiffer;
use Recombinator\Support\PipelineConsole;
use Recombinator\Support\Sandbox;
use Recombinator\Domain\ScopeStore;
use Recombinator\Transformation\VisitorPipeline;
use Recombinator\Transformation\Visitor\VisitorMeta;

$console = new PipelineConsole(new ColorDiffer());

// ════════════════════════════════════════════════════════════════════════════
// Entry point + file map
// ════════════════════════════════════════════════════════════════════════════

$entryPoint = __DIR__ . '/../examples/08-syntax-zoo/index.php';
$srcDir     = __DIR__ . '/../examples/08-syntax-zoo/src';
$resultPath = __DIR__ . '/../examples/08-syntax-zoo/result.php';

$fileMap = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcDir)) as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $relPath            = 'src/' . $file->getFilename();
        $fileMap[$relPath]  = file_get_contents($file->getPathname());
    }
}

$printer = new Printer();
$parser  = new ParserFactory()->createForNewestSupportedVersion();

$console->banner('RECOMBINATOR  ·  SYNTAX ZOO');

$ast = $parser->parse(file_get_contents($entryPoint)) ?? [];

// ════════════════════════════════════════════════════════════════════════════
// Helpers
// ════════════════════════════════════════════════════════════════════════════

/** Read name+desc from #[VisitorMeta] attribute; fall back to class short name. */
$meta = static function (object $visitor): VisitorMeta {
    $ref   = new ReflectionClass($visitor);
    $attrs = $ref->getAttributes(VisitorMeta::class);
    if (!empty($attrs)) {
        /** @var VisitorMeta $m */
        $m = $attrs[0]->newInstance();
        return new VisitorMeta(desc: $m->desc, name: $m->name ?: $ref->getShortName());
    }
    return new VisitorMeta(desc: '', name: $ref->getShortName());
};

// ════════════════════════════════════════════════════════════════════════════
// Transformation pipeline
// ════════════════════════════════════════════════════════════════════════════

// Один sandbox и кэши на весь прогон: переживают пересоздание визиторов
$execCache = new ExecutionCache();
$pipeline  = new VisitorPipeline(new ScopeStore(), $execCache, new Sandbox($execCache), new ArrayObject(), $fileMap, 'index.php');

$totalChanged = 0;
$pass         = 0;

echo "\n";
echo $console->colorize('  ● Трансформации: ' . count($pipeline->main()) . " visitors · фиксированная точка\n", 'light_cyan');

do {
    $pass++;
    $passChanged     = false;
    $passHeaderShown = false;

    foreach ($pipeline->main() as $visitor) {
        $before  = $console->applyVisitor($ast, $visitor);
        $after   = $printer->prettyPrint($ast);

        [$diffText, $diffCount] = $console->colorDiff($before, $after);

        if ($diffCount === 0) {
            continue;
        }

        if (!$passHeaderShown) {
            echo "\n";
            echo $console->colorize("  ● Pass $pass\n", 'light_cyan');
            $passHeaderShown = true;
        }

        $m = $meta($visitor);
        $console->stepHeader($m->name, $m->desc, $diffText, $diffCount);

        $passChanged   = true;
        $totalChanged += $diffCount;
    }
} while ($passChanged && $pass < 15);

// ════════════════════════════════════════════════════════════════════════════
// Readability
// ════════════════════════════════════════════════════════════════════════════

echo "\n";
echo $console->colorize('  ● Читаемость: изолированный шаг · свёртка сложных выражений' . "\n", 'light_cyan');

$readabilityChanged = 0;
foreach (VisitorPipeline::readability() as $visitor) {
    $before  = $console->applyVisitor($ast, $visitor);
    $after   = $printer->prettyPrint($ast);

    [$diffText, $diffCount] = $console->colorDiff($before, $after);

    if ($diffCount === 0) {
        continue;
    }

    $m = $meta($visitor);
    $console->stepHeader($m->name, $m->desc, $diffText, $diffCount);
    $readabilityChanged += $diffCount;
}

if ($readabilityChanged === 0) {
    echo $console->colorize("    Нет вложенных выражений для выноса.\n", 'dark_gray');
}

// ════════════════════════════════════════════════════════════════════════════
// Summary
// ════════════════════════════════════════════════════════════════════════════

echo "\n";
$console->hr('═');
if ($totalChanged === 0) {
    echo $console->colorize("  Код уже оптимален — изменений нет.\n", 'light_green');
} else {
    echo $console->colorize(sprintf(
        "  Итого: %d строк преобразовано за %d %s\n",
        $totalChanged,
        $pass - 1,
        ($pass - 1) === 1 ? 'проход' : 'прохода',
    ), 'light_green');
}
$console->hr('═');

// ════════════════════════════════════════════════════════════════════════════
// Final result
// ════════════════════════════════════════════════════════════════════════════

$console->banner('ИТОГОВЫЙ РЕЗУЛЬТАТ');
echo "\n";
$final = $printer->prettyPrintFile($ast);
echo $final;
echo "\n";

file_put_contents($resultPath, $final);
echo "\n";
$console->hr('─');
echo $console->colorize("  Результат сохранён: $resultPath\n", 'light_green');
$console->hr('─');
