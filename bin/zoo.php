#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as Printer;
use Recombinator\Support\ColorDiffer;
use Recombinator\Support\PipelineConsole;
use Recombinator\Transformation\Visitor\BinaryAndIssetVisitor;
use Recombinator\Transformation\Visitor\DeadBranchVisitor;
use Recombinator\Transformation\Visitor\CallFunctionVisitor;
use Recombinator\Transformation\Visitor\ClassInlinerVisitor;
use Recombinator\Transformation\Visitor\CoalesceNullRemoveVisitor;
use Recombinator\Transformation\Visitor\ConcatAssertVisitor;
use Recombinator\Transformation\Visitor\ConstClassVisitor;
use Recombinator\Transformation\Visitor\ConstFoldVisitor;
use Recombinator\Transformation\Visitor\PrintNormalizeVisitor;
use Recombinator\Transformation\Visitor\ConstructorAndMethodsVisitor;
use Recombinator\Transformation\Visitor\EvalStandardFunction;
use Recombinator\Transformation\Visitor\FlattenInheritanceVisitor;
use Recombinator\Transformation\Visitor\FunctionBodyCollectorVisitor;
use Recombinator\Transformation\Visitor\FunctionScopeVisitor;
use Recombinator\Transformation\Visitor\PreExecutionVisitor;
use Recombinator\Transformation\Visitor\PropertyAccessVisitor;
use Recombinator\Transformation\Visitor\RemoveCommentsVisitor;
use Recombinator\Transformation\Visitor\EnumCaseInlinerVisitor;
use Recombinator\Transformation\Visitor\StaticMethodInlinerVisitor;
use Recombinator\Transformation\Visitor\RemoveSplAutoloadVisitor;
use Recombinator\Transformation\Visitor\RemoveGlobalUseVisitor;
use Recombinator\Transformation\Visitor\RemoveInterfacesVisitor;
use Recombinator\Transformation\Visitor\InlineEmptySubclassVisitor;
use Recombinator\Transformation\Visitor\RemoveUnusedClassVisitor;
use Recombinator\Transformation\Visitor\RemoveUnusedPureVisitor;
use Recombinator\Transformation\Visitor\RemoveDeadLoopVisitor;
use Recombinator\Transformation\Visitor\RemoveDeadCodeAfterJumpVisitor;
use Recombinator\Transformation\Visitor\RemoveUnusedClosureCapturesVisitor;
use Recombinator\Transformation\Visitor\DestructuringExpandVisitor;
use Recombinator\Transformation\Visitor\InstanceofSimplifierVisitor;
use Recombinator\Transformation\Visitor\ReadonlyPropertyAccessVisitor;
use Recombinator\Transformation\Visitor\PropertyStateTrackerVisitor;
use Recombinator\Transformation\Visitor\StaticArrayStateVisitor;
use Recombinator\Transformation\Visitor\BoundedWhileUnrollerVisitor;
use Recombinator\Transformation\Visitor\ForeachLiteralUnrollerVisitor;
use Recombinator\Transformation\Visitor\MutablePropertyTrackerVisitor;
use Recombinator\Transformation\Visitor\SimpleMethodCallInlinerVisitor;
use Recombinator\Transformation\Visitor\IndexedPropGetterResolverVisitor;
use Recombinator\Transformation\Visitor\TraitInlinerVisitor;
use Recombinator\Transformation\Visitor\StripLocalClassTypeHintsVisitor;
use Recombinator\Transformation\Visitor\RemoveUnusedPropWriteVisitor;
use Recombinator\Transformation\Visitor\ObjectArrayTrackerVisitor;
use Recombinator\Transformation\Visitor\ScopeVisitor;
use Recombinator\Transformation\Visitor\SideEffectMarkerVisitor;
use Recombinator\Transformation\Visitor\SingleUseInlinerVisitor;
use Recombinator\Transformation\Visitor\TernarReturnVisitor;
use Recombinator\Transformation\Visitor\UseImportVisitor;
use Recombinator\Transformation\Visitor\VarToScalarVisitor;
use Recombinator\Transformation\Visitor\VisitorMeta;
use Recombinator\Transformation\Visitor\CodeBlockVisitor;
use Recombinator\Transformation\Visitor\ConcatInterpolateVisitor;
use Recombinator\Transformation\Visitor\ReadabilityVisitor;

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

$steps = [
    // ── Импорт классов из use SyntaxZoo\... ─────────────────────────────────
    fn() => new UseImportVisitor($fileMap),
    fn() => new RemoveGlobalUseVisitor(),
    fn() => new RemoveSplAutoloadVisitor(),

    // ── Анализ ──────────────────────────────────────────────────────────────
    fn() => new SideEffectMarkerVisitor(),

    // ── Упрощение выражений ─────────────────────────────────────────────────
    fn() => new RemoveCommentsVisitor(),
    fn() => new BinaryAndIssetVisitor(),
    fn() => new CoalesceNullRemoveVisitor(),
    fn() => new ReadonlyPropertyAccessVisitor(),
    fn() => new SimpleMethodCallInlinerVisitor(),
    fn() => new PropertyStateTrackerVisitor(),
    fn() => new StaticArrayStateVisitor(),
    fn() => new BoundedWhileUnrollerVisitor(),
    fn() => new ForeachLiteralUnrollerVisitor(),
    fn() => new MutablePropertyTrackerVisitor(),
    fn() => new ConcatAssertVisitor(),
    fn() => new EvalStandardFunction(),
    fn() => new PreExecutionVisitor(),
    fn() => new VarToScalarVisitor(),
    fn() => new ConstFoldVisitor(),
    fn() => new PrintNormalizeVisitor(),
    fn() => new DeadBranchVisitor(),
    fn() => new DestructuringExpandVisitor(),
    fn() => new InstanceofSimplifierVisitor(),
    fn() => new SingleUseInlinerVisitor(),

    // ── Инлайнинг иерархии классов ──────────────────────────────────────────
    fn() => new RemoveInterfacesVisitor(),
    fn() => new TraitInlinerVisitor(),
    fn() => new FlattenInheritanceVisitor(),

    // ── Разворачивание getter-цепочек через отслеживание сеттеров ───────────
    fn() => new IndexedPropGetterResolverVisitor(),

    // ── Инлайнинг enum ──────────────────────────────────────────────────────
    fn() => new EnumCaseInlinerVisitor(),

    // ── Инлайнинг статических методов ───────────────────────────────────────
    fn() => new StaticMethodInlinerVisitor(),

    // ── Разбивка на скоупы (подготовка кеша) ───────────────────────────────
    fn() => new ScopeVisitor(),

    // ── Инлайнинг функций ───────────────────────────────────────────────────
    fn() => new FunctionBodyCollectorVisitor(),
    fn() => new FunctionScopeVisitor(),
    fn() => new CallFunctionVisitor(),

    // ── Удаление type-hints локальных классов и мёртвых prop-записей ────────
    fn() => new StripLocalClassTypeHintsVisitor(),
    fn() => new RemoveUnusedPropWriteVisitor(),

    // ── Отслеживание коллекций объектов (admit/bySpecies/all) ────────────────
    fn() => new ObjectArrayTrackerVisitor(),

    // ── Инлайнинг классов ───────────────────────────────────────────────────
    fn() => new ConstructorAndMethodsVisitor(),
    fn() => new PropertyAccessVisitor(),
    fn() => new ConstClassVisitor(),
    fn() => new TernarReturnVisitor(),
    fn() => new ClassInlinerVisitor(),

    // ── Удаление неиспользуемых классов ─────────────────────────────────────
    fn() => new InlineEmptySubclassVisitor(),
    fn() => new RemoveDeadCodeAfterJumpVisitor(),
    fn() => new RemoveUnusedClosureCapturesVisitor(),
    fn() => new RemoveUnusedClassVisitor(),
    fn() => new RemoveUnusedPureVisitor(),
    fn() => new RemoveDeadLoopVisitor(),
];

$totalChanged = 0;
$pass         = 0;

echo "\n";
echo $console->colorize('  ● Трансформации: ' . count($steps) . " visitors · фиксированная точка\n", 'light_cyan');

do {
    $pass++;
    $passChanged     = false;
    $passHeaderShown = false;

    foreach ($steps as $factory) {
        $visitor = $factory();
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

$readabilitySteps = [
    fn() => new ReadabilityVisitor(),
    fn() => new ConcatInterpolateVisitor(),
    fn() => new CodeBlockVisitor(),
];

echo "\n";
echo $console->colorize('  ● Читаемость: изолированный шаг · свёртка сложных выражений' . "\n", 'light_cyan');

$readabilityChanged = 0;
foreach ($readabilitySteps as $factory) {
    $visitor = $factory();
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
