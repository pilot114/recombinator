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

    echo json_encode(['examples' => $examples], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
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
use Recombinator\Core\ExecutionCache;
use Recombinator\Domain\ScopeStore;
use Recombinator\Support\LineDiffer;
use Recombinator\Support\Sandbox;
use Recombinator\Transformation\Visitor\BinaryAndIssetVisitor;
use Recombinator\Transformation\Visitor\BoundedWhileUnrollerVisitor;
use Recombinator\Transformation\Visitor\CallFunctionVisitor;
use Recombinator\Transformation\Visitor\ClassInlinerVisitor;
use Recombinator\Transformation\Visitor\ClosureCallInlinerVisitor;
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
use Recombinator\Transformation\Visitor\NewArgHoistVisitor;
use Recombinator\Transformation\Visitor\ObjectMethodInlinerVisitor;
use Recombinator\Transformation\Visitor\ObjectArrayTrackerVisitor;
use Recombinator\Transformation\Visitor\ObjectStateSpecializerVisitor;
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
use Recombinator\Transformation\Visitor\RemoveUnusedMethodVisitor;
use Recombinator\Transformation\Visitor\PrivateMethodInlinerVisitor;
use Recombinator\Transformation\Visitor\SingleImplementationInterfaceVisitor;
use Recombinator\Transformation\Visitor\ContainerEntryVisitor;
use Recombinator\Transformation\Visitor\StaticMethodExtractVisitor;
use Recombinator\Transformation\Visitor\OriginalClassAliasVisitor;
use Recombinator\Transformation\Visitor\ProgramEvaluatorVisitor;
use Recombinator\Transformation\Visitor\ExternalParentGuardVisitor;
use Recombinator\Transformation\Visitor\StaticPropConstVisitor;
use Recombinator\Transformation\Visitor\ClassExistsFoldVisitor;
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
use SebastianBergmann\Diff\Output\StrictUnifiedDiffOutputBuilder;

// Лимит времени — на один проход конвейера (выставляется заново перед каждым проходом).
// Запрос отдаёт промежуточный результат, когда прошло REQUEST_BUDGET секунд, а состояние
// прогона сохраняется на диск: следующий запрос продолжает с того же места.
$timeout = 60;
const REQUEST_BUDGET = 25;
// Полный код «до/после» в шагах — только для небольших программ, иначе только diff
const STEP_CODE_LIMIT = 200_000;
set_time_limit($timeout);
ini_set('memory_limit', '5G');
$requestStart = microtime(true);

$body  = (string) file_get_contents('php://input');
$input = json_decode($body, true);

$runCacheDir = __DIR__ . '/../var/cache/runs';

if (($input['action'] ?? '') === 'reset-cache') {
    $removed = 0;
    foreach (glob($runCacheDir . '/*') ?: [] as $file) {
        $removed += (int) @unlink($file);
    }

    echo json_encode(['ok' => true, 'removed' => $removed]);
    exit;
}

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
    // Файлы корня проекта (bootstrap.php, settings.php и т.п.) подключаются через require
    foreach (glob($projectDir . '/*.php') ?: [] as $rootFile) {
        $fileMap[basename($rootFile)] = (string) file_get_contents($rootFile);
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
$differ    = new LineDiffer(new StrictUnifiedDiffOutputBuilder(['addLineNumbers' => false, 'header' => '', 'emitNoLineEndEofWarning' => false]));

/**
 * Версия конвейера: меняется при любой правке правил, и старый кэш перестаёт подходить.
 */
function pipelineVersion(): string
{
    $parts = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../src', RecursiveDirectoryIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if ($file->isFile()) {
            $parts[] = $file->getPathname() . ':' . $file->getMTime() . ':' . $file->getSize();
        }
    }

    sort($parts);
    $parts[] = (string) @filemtime(__FILE__);

    return sha1(implode("\n", $parts));
}

/** Снимает служебные ссылки NodeConnectingVisitor: они восстанавливаются перед каждым визитором. */
function stripConnections(array $ast): array
{
    $t = new NodeTraverser(new class extends NodeVisitorAbstract {
        public function enterNode(PhpParser\Node $node)
        {
            $attrs = $node->getAttributes();
            unset($attrs['parent'], $attrs['next'], $attrs['previous']);
            $node->setAttributes($attrs);
            return null;
        }
    });

    return $t->traverse($ast);
}

$runKey   = sha1(serialize([$code, $fileMap, $entryRel ?? '']) . pipelineVersion());
$runFile  = $runCacheDir . '/' . $runKey . '.run';
$lockFile = $runCacheDir . '/' . $runKey . '.lock';
if (!is_dir($runCacheDir)) {
    @mkdir($runCacheDir, 0777, true);
}

// Один прогон одного входа за раз: параллельный запрос просто ждёт
$lock = fopen($lockFile, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo json_encode(['partial' => true, 'busy' => true, 'steps' => [], 'steps_from' => -1]);
    exit;
}

/** @var array<string, mixed>|null $run */
$run = null;
if (is_file($runFile)) {
    $raw = @gzuncompress((string) file_get_contents($runFile));
    $run = $raw !== false ? @unserialize($raw) : null;
    if (!is_array($run)) {
        $run = null;
    }
}

/** Ответ клиенту: шаги начиная с $stepsFrom (клиент дописывает их к уже полученным). */
function respond(array $run, int $stepsFrom, bool $partial, bool $cached): never
{
    echo json_encode([
        'original'      => $run['original'],
        'final'         => $run['final'] ?? null,
        'steps'         => array_slice($run['steps'], $stepsFrom),
        'steps_from'    => $stepsFrom,
        'passes'        => $run['passes'],
        'partial'       => $partial,
        'cached'        => $cached,
        'total_time_ms' => $run['total_time_ms'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if ($run !== null && $run['done']) {
    respond($run, 0, false, true);
}

$stepsFrom = max(0, min((int) ($input['steps_from'] ?? 0), count($run['steps'] ?? [])));

if ($run === null) {
    try {
        $ast = $phpParser->parse($code) ?? [];
    } catch (PhpParserError $e) {
        echo json_encode(['error' => 'Parse error: ' . $e->getMessage()]);
        exit;
    }

    $run = [
        'original'      => $code,
        'ast'           => $ast,
        'steps'         => [],
        'pass'          => 1,
        'idle_runs'     => 0,
        'passes'        => 0,
        'done'          => false,
        'final'         => null,
        'total_time_ms' => 0.0,
        'scope_store'   => new ScopeStore(),
        'exec_cache'    => new ExecutionCache(),
        'enum_cache'    => new ArrayObject(),
    ];
    $stepsFrom = 0;
}

/** Сохраняет состояние прогона (атомарно: через временный файл). */
function saveRun(string $runFile, array $run): void
{
    $run['ast'] = stripConnections($run['ast']);
    $tmp        = $runFile . '.' . getmypid() . '.tmp';
    file_put_contents($tmp, gzcompress(serialize($run), 1));
    rename($tmp, $runFile);
}

// Проход, не уложившийся в лимит, — фатальная ошибка PHP; сообщаем о ней клиенту JSON-ом.
// Состояние после предыдущего прохода уже сохранено.
$currentPass = $run['pass'];
register_shutdown_function(static function () use (&$currentPass, $timeout): void {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        $message = str_contains($error['message'], 'Maximum execution time')
            ? sprintf('Проход %d не уложился в лимит %d с', $currentPass, $timeout)
            : $error['message'];
        echo json_encode(['error' => $message]);
    }
});

$ast = $run['ast'];

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

// Состояние, которое переживает проходы (и запросы — через сохранённый прогон)
$ss = $run['scope_store'];
// Визиторы пересоздаются на каждом проходе, а кеш предвыполнения — нет
$sandbox = new Sandbox($run['exec_cache']);
// Кэш enum-методов: enum к поздним проходам уже встроен, а значения его методов нужны
$enumCache = $run['enum_cache'];

$makeMainVisitors = static function () use ($ss, $sandbox, $enumCache, $fileMap, $entryRel): array {
    $visitors = [];
    if (!empty($fileMap)) {
        $visitors[] = new FileMapIncludeVisitor($fileMap, $entryRel);
        $visitors[] = new UseImportVisitor($fileMap);
        // Сразу убираем недостижимые классы: все следующие визиторы обходят меньше кода
        $visitors[] = new RemoveUnusedClassVisitor();
        $visitors[] = new ExternalParentGuardVisitor();
    }
    // Замкнутый мир: все классы программы известны, только если их подтянули из карты файлов
    $closedWorld = !empty($fileMap) ? [new ClassExistsFoldVisitor()] : [];
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
        new StaticPropConstVisitor(),
        ...$closedWorld,
        new PreExecutionVisitor($ss, $sandbox),
        new VarToScalarVisitor($ss),
        new ConstFoldVisitor(),
        new PrintNormalizeVisitor(),
        new DeadBranchVisitor(),
        new DestructuringExpandVisitor(),
        new InstanceofSimplifierVisitor(),
        new SingleUseInlinerVisitor(),

        // Инлайнинг иерархии классов
        new SingleImplementationInterfaceVisitor($fileMap),
        new RemoveInterfacesVisitor(),
        new TraitInlinerVisitor(),
        new FlattenInheritanceVisitor(),

        // Разворачивание getter-цепочек
        new IndexedPropGetterResolverVisitor($sandbox, $enumCache),

        // Инлайнинг enum и статических методов
        new EnumCaseInlinerVisitor(),
        new StaticMethodInlinerVisitor(),
        new StaticMethodExtractVisitor(),

        // Инлайнинг функций
        new ScopeVisitor(),
        new FunctionBodyCollectorVisitor($ss),
        new FunctionScopeVisitor($ss),
        new CallFunctionVisitor($ss),
        new PureFunctionEvaluatorVisitor(),
        new RemoveUnusedFunctionVisitor(),

        // Развёртка живых объектов: new из аргументов, процедуры, состояние, замыкания
        new NewArgHoistVisitor(),
        new ObjectMethodInlinerVisitor(),
        new PrivateMethodInlinerVisitor(),
        new ObjectStateSpecializerVisitor(),
        new ContainerEntryVisitor(),
        new ClosureCallInlinerVisitor(),

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
        new RemoveUnusedMethodVisitor(null, $fileMap),
        new RemoveUnusedClassVisitor(),
        new RemoveUnusedPureVisitor(),
        new RemoveDeadLoopVisitor(),

        // Детерминированная программа целиком → её вывод
        new ProgramEvaluatorVisitor(),
    ]);
};

// Readability visitors run once after main loop converges (like inliner.php)
$makeReadabilityVisitors = static fn(): array => [
    new ReadabilityVisitor(),
    new ConcatInterpolateVisitor(),
    new CodeBlockVisitor(),
    new OriginalClassAliasVisitor(),
];

/** Шаг для журнала: полный код «до/после» — только если он не слишком велик. */
function makeStep(int $pass, NodeVisitorAbstract $visitor, string $before, string $after, string $diff, float $timeMs): array
{
    $meta  = getVisitorMeta($visitor);
    $small = strlen($before) + strlen($after) <= 2 * STEP_CODE_LIMIT;

    return [
        'pass'    => $pass,
        'visitor' => $meta['name'],
        'desc'    => $meta['desc'],
        'before'  => $small ? "<?php\n" . $before : null,
        'after'   => $small ? "<?php\n" . $after : null,
        'diff'    => $diff,
        'time_ms' => $timeMs,
    ];
}

// ── Phase 1: optimisation loop ────────────────────────────────────────────────
// Цикл продолжается до полной сходимости. Сходимость — полный круг visitor'ов подряд
// без изменений, даже если он начался в середине прохода: visitor'ы после последнего
// изменения уже видели текущий код, и повторный прогон для них ничего не даст.
// Если бюджет запроса исчерпан (проверяется перед каждым visitor'ом), состояние
// сохраняется вместе с позицией в проходе, а клиент получает промежуточный результат
// и запрашивает продолжение. Лимит времени PHP выставляется заново на каждый проход.
$visitorCount = count($makeMainVisitors());
$converged    = false;
$didWork      = false;

while (true) {
    $pass        = $run['pass'];
    $currentPass = $pass;
    $startIndex  = $run['visitor_index'] ?? 0;
    if ($startIndex === 0) {
        $run['pass_changed'] = false;
    }

    set_time_limit($timeout);

    // Вычисляем «before» один раз перед проходом; далее переиспользуем «after»
    // предыдущего visitor как «before» следующего — экономим вызов prettyPrint.
    $currentCode = $printer->prettyPrint($ast);
    $reconnect   = true; // первый visitor (в том числе после продолжения) требует NodeConnectingVisitor

    foreach (array_slice($makeMainVisitors(), $startIndex, null, true) as $index => $visitor) {
        if ($didWork && microtime(true) - $requestStart >= REQUEST_BUDGET) {
            $run['visitor_index'] = $index;
            $run['ast']           = $ast;
            saveRun($runFile, $run);
            respond($run, $stepsFrom, true, false);
        }

        $didWork     = true;
        $prevCode    = $currentCode;
        $r           = applyOneVisitor($ast, $visitor, $prevCode, $reconnect);
        $currentCode = $r['after'];
        $run['total_time_ms'] += $r['time_ms'];

        $changed   = ($prevCode !== $currentCode);
        $reconnect = $changed; // следующий visitor нуждается в reconnect только при изменении AST

        if (!$changed) {
            if (++$run['idle_runs'] >= $visitorCount) {
                $converged = true;
                break;
            }

            continue;
        }

        $run['idle_runs']    = 0;
        $run['steps'][]      = makeStep($pass, $visitor, $prevCode, $currentCode, $differ->diff($prevCode, $currentCode), $r['time_ms']);
        $run['pass_changed'] = true;
    }

    $run['passes']        = $run['pass_changed'] ? $pass : $pass - 1;
    $run['pass']          = $pass + 1;
    $run['visitor_index'] = 0;

    if ($converged || !$run['pass_changed']) {
        break;
    }

    // Чекпоинт после прохода: если следующий упадёт по лимиту, продолжим отсюда
    $run['ast'] = $ast;
    saveRun($runFile, $run);
}

// ── Phase 2: readability (single isolated pass) ───────────────────────────────
$readabilityStart = microtime(true);
$currentCode      = $printer->prettyPrint($ast);
$reconnect        = true;

foreach ($makeReadabilityVisitors() as $visitor) {
    $prevCode    = $currentCode;
    $r           = applyOneVisitor($ast, $visitor, $prevCode, $reconnect);
    $currentCode = $r['after'];

    $changed   = ($prevCode !== $currentCode);
    $reconnect = $changed;

    if (!$changed) {
        continue;
    }

    $run['steps'][] = makeStep($pass, $visitor, $prevCode, $currentCode, $differ->diff($prevCode, $currentCode), $r['time_ms']);
    $run['passes']  = $pass;
}

$run['total_time_ms'] += (microtime(true) - $readabilityStart) * 1000.0;
$run['ast']            = $ast;
$run['final']          = "<?php\n" . $printer->prettyPrint($ast);
$run['done']           = true;
saveRun($runFile, $run);

respond($run, $stepsFrom, false, false);
