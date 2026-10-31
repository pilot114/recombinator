<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as Printer;
use Recombinator\Support\ColorDiffer;
use Recombinator\Support\Inliner;
use Recombinator\Core\ExecutionCache;
use Recombinator\Domain\ScopeStore;
use Recombinator\Support\PipelineConsole;
use Recombinator\Support\Sandbox;
use Recombinator\Transformation\VisitorPipeline;
use Recombinator\Transformation\Visitor\VisitorMeta;

$console = new PipelineConsole(new ColorDiffer());

// ════════════════════════════════════════════════════════════════════════════
// Step 0 – Inliner
// ════════════════════════════════════════════════════════════════════════════

$entryPoint = __DIR__ . '/../examples/05-auth/03-index.php';
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

// Один sandbox и кэши на весь прогон: переживают пересоздание визиторов
$execCache = new ExecutionCache();
$pipeline  = new VisitorPipeline(new ScopeStore(), $execCache, new Sandbox($execCache), new ArrayObject());

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
echo $console->colorize('  ● Трансформации: ' . count($pipeline->main()) . " visitors · фиксированная точка\n", 'light_cyan');

do {
    $pass++;
    $passChanged    = false;
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
} while ($passChanged && $pass < 10);

// ════════════════════════════════════════════════════════════════════════════
// Readability – isolated final step (runs once after optimisation converges)
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
echo $printer->prettyPrintFile($ast);
echo "\n";
