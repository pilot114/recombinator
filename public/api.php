<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── GET: список примеров ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $dir      = __DIR__ . '/../examples';
    $allMeta  = json_decode((string) file_get_contents($dir . '/meta.json'), true) ?? [];
    $examples = [];

    foreach (glob($dir . '/*/') as $exampleDir) {
        $dirName = basename($exampleDir);
        $meta    = $allMeta[$dirName] ?? [];
        $id      = preg_replace('/^\d+-/', '', $dirName);

        if (($meta['type'] ?? '') === 'project') {
            $rawEntry = $meta['entry'] ?? '';
            $entries  = is_array($rawEntry) ? array_values($rawEntry) : [$rawEntry];

            $countPhp = static function (string $path): int {
                if (!is_dir($path)) {
                    return 0;
                }
                $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS));
                $n  = 0;
                foreach ($it as $f) {
                    if ($f->isFile() && $f->getExtension() === 'php') {
                        $n++;
                    }
                }
                return $n;
            };

            $examples[] = [
                'id'          => $id,
                'name'        => (string) ($meta['name'] ?? $id),
                'type'        => 'project',
                'description' => (string) ($meta['description'] ?? ''),
                'entries'     => $entries,
                'url'         => (string) ($meta['url'] ?? ''),
                'srcCount'    => $countPhp($exampleDir . 'src'),
                'vendorCount' => $countPhp($exampleDir . 'vendor'),
            ];
            continue;
        }

        $files = [];
        foreach (glob($exampleDir . '*.php') as $phpFile) {
            $files[] = [
                'name' => preg_replace('/^\d+-/', '', basename($phpFile)),
                'code' => file_get_contents($phpFile),
            ];
        }

        $examples[] = [
            'id'    => $id,
            'name'  => (string) ($meta['name'] ?? $id),
            'files' => $files,
        ];
    }

    echo json_encode(['examples' => $examples], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/../vendor/autoload.php';

use PhpParser\Error as PhpParserError;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NodeConnectingVisitor;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as Printer;
use Recombinator\Domain\ScopeStore;
use Recombinator\Transformation\Visitor\BinaryAndIssetVisitor;
use Recombinator\Transformation\Visitor\BoundedWhileUnrollerVisitor;
use Recombinator\Transformation\Visitor\CallFunctionVisitor;
use Recombinator\Transformation\Visitor\ClassInlinerVisitor;
use Recombinator\Transformation\Visitor\CoalesceNullRemoveVisitor;
use Recombinator\Transformation\Visitor\CodeBlockVisitor;
use Recombinator\Transformation\Visitor\ConcatAssertVisitor;
use Recombinator\Transformation\Visitor\ConcatInterpolateVisitor;
use Recombinator\Transformation\Visitor\ConstClassVisitor;
use Recombinator\Transformation\Visitor\ConstFoldVisitor;
use Recombinator\Transformation\Visitor\PrintNormalizeVisitor;
use Recombinator\Transformation\Visitor\ConstructorAndMethodsVisitor;
use Recombinator\Transformation\Visitor\DeadBranchVisitor;
use Recombinator\Transformation\Visitor\DestructuringExpandVisitor;
use Recombinator\Transformation\Visitor\EnumCaseInlinerVisitor;
use Recombinator\Transformation\Visitor\EvalStandardFunction;
use Recombinator\Transformation\Visitor\FileMapIncludeVisitor;
use Recombinator\Transformation\Visitor\FlattenInheritanceVisitor;
use Recombinator\Transformation\Visitor\ForeachLiteralUnrollerVisitor;
use Recombinator\Transformation\Visitor\FunctionBodyCollectorVisitor;
use Recombinator\Transformation\Visitor\FunctionScopeVisitor;
use Recombinator\Transformation\Visitor\IndexedPropGetterResolverVisitor;
use Recombinator\Transformation\Visitor\InlineEmptySubclassVisitor;
use Recombinator\Transformation\Visitor\InstanceofSimplifierVisitor;
use Recombinator\Transformation\Visitor\MethodToSingleReturnVisitor;
use Recombinator\Transformation\Visitor\MutablePropertyTrackerVisitor;
use Recombinator\Transformation\Visitor\ObjectArrayTrackerVisitor;
use Recombinator\Transformation\Visitor\PreExecutionVisitor;
use Recombinator\Transformation\Visitor\PropertyAccessVisitor;
use Recombinator\Transformation\Visitor\PropertyStateTrackerVisitor;
use Recombinator\Transformation\Visitor\PureFunctionEvaluatorVisitor;
use Recombinator\Transformation\Visitor\ReadabilityVisitor;
use Recombinator\Transformation\Visitor\ReadonlyPropertyAccessVisitor;
use Recombinator\Transformation\Visitor\RemoveCommentsVisitor;
use Recombinator\Transformation\Visitor\RemoveDeadCodeAfterJumpVisitor;
use Recombinator\Transformation\Visitor\RemoveDeadLoopVisitor;
use Recombinator\Transformation\Visitor\RemoveGlobalUseVisitor;
use Recombinator\Transformation\Visitor\RemoveInterfacesVisitor;
use Recombinator\Transformation\Visitor\RemoveSplAutoloadVisitor;
use Recombinator\Transformation\Visitor\RemoveUnusedClassVisitor;
use Recombinator\Transformation\Visitor\RemoveUnusedClosureCapturesVisitor;
use Recombinator\Transformation\Visitor\RemoveUnusedFunctionVisitor;
use Recombinator\Transformation\Visitor\RemoveUnusedPropWriteVisitor;
use Recombinator\Transformation\Visitor\RemoveUnusedPureVisitor;
use Recombinator\Transformation\Visitor\ScopeVisitor;
use Recombinator\Transformation\Visitor\SimpleMethodCallInlinerVisitor;
use Recombinator\Transformation\Visitor\SingleUseInlinerVisitor;
use Recombinator\Transformation\Visitor\StaticArrayStateVisitor;
use Recombinator\Transformation\Visitor\StaticMethodInlinerVisitor;
use Recombinator\Transformation\Visitor\StripLocalClassTypeHintsVisitor;
use Recombinator\Transformation\Visitor\TernarReturnVisitor;
use Recombinator\Transformation\Visitor\TraitInlinerVisitor;
use Recombinator\Transformation\Visitor\UseImportVisitor;
use Recombinator\Transformation\Visitor\VarToScalarVisitor;
use Recombinator\Transformation\Visitor\VisitorMeta;
use SebastianBergmann\Diff\Differ;
use SebastianBergmann\Diff\Output\UnifiedDiffOutputBuilder;

$timeout = 60;
set_time_limit($timeout);
ini_set('memory_limit', '5G');
$requestStart = microtime(true);

$body  = (string) file_get_contents('php://input');
$input = json_decode($body, true);

// Поддерживаем три формата: {code}, {files, entry}, {project, entry}
/** @var array<string, string> $fileMap */
$fileMap = [];

if (!empty($input['project'])) {
    $projectId  = preg_replace('/[^a-z0-9-]/', '', (string) $input['project']);
    $examplesDir = __DIR__ . '/../examples';
    $allMeta    = json_decode((string) file_get_contents($examplesDir . '/meta.json'), true) ?? [];
    $projectDir = null;
    foreach (glob($examplesDir . '/*/') as $d) {
        $dn = basename($d);
        if (preg_replace('/^\d+-/', '', $dn) === $projectId) {
            $meta = $allMeta[$dn] ?? [];
            if (($meta['type'] ?? '') === 'project') {
                $projectDir = rtrim($d, '/');
                $entryRel   = (string) ($input['entry'] ?? $meta['entry'] ?? '');
            }
            break;
        }
    }
    if ($projectDir === null) {
        echo json_encode(['error' => 'Unknown project: ' . $projectId]);
        exit;
    }
    foreach (['src', 'vendor'] as $subdir) {
        $scanDir = $projectDir . '/' . $subdir;
        if (!is_dir($scanDir)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($scanDir, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $rel = ltrim(str_replace($projectDir, '', $file->getPathname()), '/');
                $fileMap[$rel] = (string) file_get_contents($file->getPathname());
            }
        }
    }
    if (!empty($entryRel) && file_exists($projectDir . '/' . $entryRel)) {
        $fileMap[$entryRel] = (string) file_get_contents($projectDir . '/' . $entryRel);
        $code = $fileMap[$entryRel];
    } else {
        $code = reset($fileMap) ?: '';
        $entryRel = array_key_first($fileMap) ?? '';
    }
} elseif (!empty($input['files']) && is_array($input['files'])) {
    $entryRel = (string) ($input['entry'] ?? '');
    $code     = '';
    foreach ($input['files'] as $file) {
        if (!is_array($file) || !isset($file['name'], $file['code'])) {
            continue;
        }
        $fileMap[(string) $file['name']] = (string) $file['code'];
        if ((string) $file['name'] === $entryRel) {
            $code = (string) $file['code'];
        }
    }
    if ($code === '') {
        $code     = end($fileMap) ?: '';
        $entryRel = array_key_last($fileMap) ?? '';
    }
} else {
    $code     = trim((string) ($input['code'] ?? ''));
    $entryRel = '';
}

if ($code === '') {
    echo json_encode(['error' => 'No code provided']);
    exit;
}

if (!str_starts_with(ltrim($code), '<?')) {
    $code = "<?php\n" . $code;
}

$phpParser = new ParserFactory()->createForNewestSupportedVersion();
$printer   = new Printer();
$differ    = new Differ(new UnifiedDiffOutputBuilder(''));

try {
    $ast = $phpParser->parse($code) ?? [];
} catch (PhpParserError $e) {
    echo json_encode(['error' => 'Parse error: ' . $e->getMessage()]);
    exit;
}

/**
 * Применяет один visitor к AST.
 *
 * @param string $before  Код до применения (передаётся снаружи, чтобы переиспользовать
 *                        строку «after» предыдущего visitor как «before» текущего).
 * @param bool   $reconnect  Запускать ли NodeConnectingVisitor. Нужен только если
 *                           предыдущий visitor изменил AST (или это первый в проходе).
 */
function applyOneVisitor(array &$ast, NodeVisitorAbstract $visitor, string $before, bool $reconnect = true): array
{
    global $printer;
    $t0 = microtime(true);

    if ($reconnect) {
        $t1 = new NodeTraverser();
        $t1->addVisitor(new NodeConnectingVisitor());
        $ast = array_values($t1->traverse($ast));
    }

    $t2 = new NodeTraverser();
    $t2->addVisitor($visitor);
    $ast = array_values($t2->traverse($ast));

    $after = $printer->prettyPrint($ast);

    return [
        'before'  => $before,
        'after'   => $after,
        'time_ms' => (microtime(true) - $t0) * 1000.0,
    ];
}

function getVisitorMeta(NodeVisitorAbstract $visitor): array
{
    $ref   = new ReflectionClass($visitor);
    $attrs = $ref->getAttributes(VisitorMeta::class);
    if (!empty($attrs)) {
        /** @var VisitorMeta $m */
        $m = $attrs[0]->newInstance();
        return ['name' => $m->name ?: $ref->getShortName(), 'desc' => $m->desc];
    }
    return ['name' => $ref->getShortName(), 'desc' => ''];
}

$ss = new ScopeStore();

$makeMainVisitors = static function () use ($ss, $fileMap, $entryRel): array {
    $visitors = [];
    if (!empty($fileMap)) {
        $visitors[] = new FileMapIncludeVisitor($fileMap, $entryRel);
        $visitors[] = new UseImportVisitor($fileMap);
    }
    return array_merge($visitors, [
        new RemoveGlobalUseVisitor(),
        new RemoveSplAutoloadVisitor(),

        // Упрощение выражений
        new RemoveCommentsVisitor(),
        new BinaryAndIssetVisitor(),
        new CoalesceNullRemoveVisitor(),
        new ReadonlyPropertyAccessVisitor(),
        new SimpleMethodCallInlinerVisitor(),
        new PropertyStateTrackerVisitor(),
        new StaticArrayStateVisitor(),
        new BoundedWhileUnrollerVisitor(),
        new ForeachLiteralUnrollerVisitor(),
        new MutablePropertyTrackerVisitor(),
        new ConcatAssertVisitor(),
        new EvalStandardFunction(),
        new PreExecutionVisitor($ss),
        new VarToScalarVisitor($ss),
        new ConstFoldVisitor(),
        new PrintNormalizeVisitor(),
        new DeadBranchVisitor(),
        new DestructuringExpandVisitor(),
        new InstanceofSimplifierVisitor(),
        new SingleUseInlinerVisitor(),

        // Инлайнинг иерархии классов
        new RemoveInterfacesVisitor(),
        new TraitInlinerVisitor(),
        new FlattenInheritanceVisitor(),

        // Разворачивание getter-цепочек
        new IndexedPropGetterResolverVisitor(),

        // Инлайнинг enum и статических методов
        new EnumCaseInlinerVisitor(),
        new StaticMethodInlinerVisitor(),

        // Инлайнинг функций
        new ScopeVisitor(),
        new FunctionBodyCollectorVisitor($ss),
        new FunctionScopeVisitor($ss),
        new CallFunctionVisitor($ss),
        new PureFunctionEvaluatorVisitor(),
        new RemoveUnusedFunctionVisitor(),

        // Инлайнинг классов
        new MethodToSingleReturnVisitor(),
        new ConstructorAndMethodsVisitor($ss),
        new PropertyAccessVisitor($ss),
        new ConstClassVisitor($ss),
        new TernarReturnVisitor(),
        new ClassInlinerVisitor(),

        // Удаление type-hints локальных классов и мёртвых prop-записей
        new StripLocalClassTypeHintsVisitor(),
        new RemoveUnusedPropWriteVisitor(),

        // Отслеживание коллекций объектов
        new ObjectArrayTrackerVisitor(),

        // Удаление неиспользуемых сущностей
        new InlineEmptySubclassVisitor(),
        new RemoveDeadCodeAfterJumpVisitor(),
        new RemoveUnusedClosureCapturesVisitor(),
        new RemoveUnusedClassVisitor(),
        new RemoveUnusedPureVisitor(),
        new RemoveDeadLoopVisitor(),
    ]);
};

// Readability visitors run once after main loop converges (like inliner.php)
$makeReadabilityVisitors = static fn(): array => [
    new ReadabilityVisitor(),
    new ConcatInterpolateVisitor(),
    new CodeBlockVisitor(),
];

$steps           = [];
$readabilityPass = false;

$deadline = $requestStart + ($timeout - 10);

// ── Phase 1: optimisation loop ────────────────────────────────────────────────
// Цикл продолжается до полной сходимости (ни один visitor не изменил AST)
// или до исчерпания лимита времени.
for ($pass = 1; ; $pass++) {
    if (microtime(true) >= $deadline) {
        break;
    }

    $passChanged = false;
    // Вычисляем «before» один раз перед проходом; далее переиспользуем «after»
    // предыдущего visitor как «before» следующего — экономим вызов prettyPrint.
    $currentCode = $printer->prettyPrint($ast);
    $reconnect   = true; // первый visitor в проходе всегда требует NodeConnectingVisitor

    foreach ($makeMainVisitors() as $visitor) {
        $prevCode    = $currentCode;
        $r           = applyOneVisitor($ast, $visitor, $prevCode, $reconnect);
        $currentCode = $r['after'];

        $changed   = ($prevCode !== $currentCode);
        $reconnect = $changed; // следующий visitor нуждается в reconnect только при изменении AST

        if (!$changed) {
            continue;
        }

        $diff = $differ->diff($prevCode, $currentCode);
        $meta = getVisitorMeta($visitor);

        $steps[] = [
            'pass'    => $pass,
            'visitor' => $meta['name'],
            'desc'    => $meta['desc'],
            'before'  => "<?php\n" . $prevCode,
            'after'   => "<?php\n" . $currentCode,
            'diff'    => $diff,
            'time_ms' => $r['time_ms'],
        ];

        $passChanged = true;
    }

    if (!$passChanged) {
        break;
    }
}

// ── Phase 2: readability (single isolated pass) ───────────────────────────────
$currentCode = $printer->prettyPrint($ast);
$reconnect   = true;

foreach ($makeReadabilityVisitors() as $visitor) {
    $prevCode    = $currentCode;
    $r           = applyOneVisitor($ast, $visitor, $prevCode, $reconnect);
    $currentCode = $r['after'];

    $changed   = ($prevCode !== $currentCode);
    $reconnect = $changed;

    if (!$changed) {
        continue;
    }

    $diff = $differ->diff($prevCode, $currentCode);
    $meta = getVisitorMeta($visitor);

    $readabilityPass = true;
    $steps[] = [
        'pass'    => $pass,
        'visitor' => $meta['name'],
        'desc'    => $meta['desc'],
        'before'  => "<?php\n" . $prevCode,
        'after'   => "<?php\n" . $currentCode,
        'diff'    => $diff,
        'time_ms' => $r['time_ms'],
    ];
}

echo json_encode([
    'original'      => $code,
    'final'         => "<?php\n" . $printer->prettyPrint($ast),
    'steps'         => $steps,
    'passes'        => $readabilityPass ? $pass : $pass - 1,
    'total_time_ms' => (microtime(true) - $requestStart) * 1000.0,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
