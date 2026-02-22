<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as Printer;
use Recombinator\Support\ColorDiffer;
use Recombinator\Support\Inliner;
use Recombinator\Support\PipelineConsole;
use Recombinator\Transformation\Visitor\BinaryAndIssetVisitor;
use Recombinator\Transformation\Visitor\CallFunctionVisitor;
use Recombinator\Transformation\Visitor\ClassInlinerVisitor;
use Recombinator\Transformation\Visitor\CoalesceNullRemoveVisitor;
use Recombinator\Transformation\Visitor\ConcatAssertVisitor;
use Recombinator\Transformation\Visitor\ConstClassVisitor;
use Recombinator\Transformation\Visitor\ConstFoldVisitor;
use Recombinator\Transformation\Visitor\SingleUseInlinerVisitor;
use Recombinator\Transformation\Visitor\ConstructorAndMethodsVisitor;
use Recombinator\Transformation\Visitor\EvalStandardFunction;
use Recombinator\Transformation\Visitor\FunctionBodyCollectorVisitor;
use Recombinator\Transformation\Visitor\FunctionScopeVisitor;
use Recombinator\Transformation\Visitor\PreExecutionVisitor;
use Recombinator\Transformation\Visitor\PropertyAccessVisitor;
use Recombinator\Transformation\Visitor\RemoveCommentsVisitor;
use Recombinator\Transformation\Visitor\ScopeVisitor;
use Recombinator\Transformation\Visitor\SideEffectMarkerVisitor;
use Recombinator\Transformation\Visitor\CodeBlockVisitor;
use Recombinator\Transformation\Visitor\ConcatInterpolateVisitor;
use Recombinator\Transformation\Visitor\ReadabilityVisitor;
use Recombinator\Transformation\Visitor\TernarReturnVisitor;
use Recombinator\Transformation\Visitor\VarToScalarVisitor;
use Recombinator\Transformation\Visitor\VisitorMeta;

$console = new PipelineConsole(new ColorDiffer());

// ════════════════════════════════════════════════════════════════════════════
// Step 0 – Inliner
// ════════════════════════════════════════════════════════════════════════════

$entryPoint = __DIR__ . '/../tests/code/index.php';
$printer    = new Printer();
$parser     = new ParserFactory()->createForNewestSupportedVersion();

$console->banner('RECOMBINATOR  ·  INLINER + OPTIMIZER');

echo "\n";
echo $console->colorize("  ● Шаг 0  Inliner\n", 'light_cyan');
echo $console->colorize("    Подстановка include/require → единый файл.\n", 'light_gray');
echo $console->colorize("    Конфликты имён устраняются уникальными префиксами (f1_, f2_, …).\n", 'light_gray');

$inlined = (new Inliner($entryPoint))->inline();
$ast     = $parser->parse($inlined) ?? [];

echo $console->colorize("\n    ✓  Инлайнировано: " . basename($entryPoint), 'light_green');
echo $console->colorize('  (' . count($ast) . " top-level узлов)\n", 'dark_gray');

// ════════════════════════════════════════════════════════════════════════════
// Transformations – visitors applied in rounds until the AST stabilises
// ════════════════════════════════════════════════════════════════════════════

$steps = [
    // ── Анализ ──────────────────────────────────────────────────────────────
    SideEffectMarkerVisitor::class,

    // ── Упрощение выражений ─────────────────────────────────────────────────
    RemoveCommentsVisitor::class,
    BinaryAndIssetVisitor::class,
    CoalesceNullRemoveVisitor::class,
    ConcatAssertVisitor::class,
    EvalStandardFunction::class,
    PreExecutionVisitor::class,
    VarToScalarVisitor::class,
    ConstFoldVisitor::class,
    SingleUseInlinerVisitor::class,

    // ── Разбивка на скоупы (подготовка кеша) ───────────────────────────────
    ScopeVisitor::class,

    // ── Инлайнинг функций ───────────────────────────────────────────────────
    FunctionBodyCollectorVisitor::class,
    FunctionScopeVisitor::class,
    CallFunctionVisitor::class,

    // ── Инлайнинг классов ───────────────────────────────────────────────────
    ConstructorAndMethodsVisitor::class,
    PropertyAccessVisitor::class,
    ConstClassVisitor::class,

    // ── Структурные упрощения ───────────────────────────────────────────────
    TernarReturnVisitor::class,

    // ── Инлайнинг классов (финальный) ───────────────────────────────────────
    ClassInlinerVisitor::class,
];

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

$totalChanged = 0;
$pass         = 0;

echo "\n";
echo $console->colorize('  ● Трансформации: ' . count($steps) . " visitors · фиксированная точка\n", 'light_cyan');

do {
    $pass++;
    $passChanged    = false;
    $passHeaderShown = false;

    foreach ($steps as $class) {
        $visitor = new $class();
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
} while ($passChanged && $pass < 10);

// ════════════════════════════════════════════════════════════════════════════
// Readability – isolated final step (runs once after optimisation converges)
// ════════════════════════════════════════════════════════════════════════════

$readabilitySteps = [
    ReadabilityVisitor::class,
    ConcatInterpolateVisitor::class,
    CodeBlockVisitor::class,
];

echo "\n";
echo $console->colorize('  ● Читаемость: изолированный шаг · свёртка сложных выражений' . "\n", 'light_cyan');

$readabilityChanged = 0;
foreach ($readabilitySteps as $class) {
    $visitor = new $class();
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
echo $printer->prettyPrintFile($ast);
echo "\n";
