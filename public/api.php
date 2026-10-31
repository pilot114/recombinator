<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

// API выполняет код (пробы, прогрев, песочница): только с той же страницы. Без заголовков
// CORS чужой сайт не пройдёт предварительный запрос, а без application/json — и простой POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !str_starts_with(strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) {
    http_response_code(415);
    echo json_encode(['error' => 'Content-Type must be application/json']);
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
                'environment' => $meta['environment'] ?? null,
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
use Recombinator\Support\RunCache;
use Recombinator\Support\Sandbox;
use Recombinator\Core\TargetEnvironment;
use Recombinator\Support\DoctrineMetadataProbe;
use Recombinator\Transformation\VisitorPipeline;
use Recombinator\Transformation\Visitor\VisitorMeta;
use SebastianBergmann\Diff\Output\StrictUnifiedDiffOutputBuilder;

// Лимит времени — на один проход конвейера (выставляется заново перед каждым проходом).
// Запрос отдаёт промежуточный результат, когда прошло REQUEST_BUDGET секунд, а состояние
// прогона сохраняется на диск: следующий запрос продолжает с того же места.
$timeout = 60;
const REQUEST_BUDGET = 25;
// Полный код «до/после» в шагах — только для небольших программ, иначе только diff
const STEP_CODE_LIMIT = 200_000;
// Ответ больше этого — без шагов прошлых прогонов (см. respond)
const RESPONSE_LIMIT = 1_000_000;
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
/** @var array<string, mixed> $environmentData допущения о целевом сервере (TargetEnvironment) */
$environmentData = [];
$projectDir      = null;

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
                $projectDir      = rtrim($d, '/');
                $entryRel        = (string) ($input['entry'] ?? $meta['entry'] ?? '');
                $environmentData = is_array($meta['environment'] ?? null) ? $meta['environment'] : [];
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

// Окружение из запроса (правка в интерфейсе) важнее описания проекта
if (is_array($input['environment'] ?? null)) {
    $environmentData = $input['environment'];
}

$environment = TargetEnvironment::fromArray($environmentData);
if ($projectDir !== null) {
    $environment = $environment->withProjectDir((string) realpath($projectDir));
}

$envArray    = $environment->toArray();
$envHash     = $environment->isEmpty() ? '' : sha1((string) json_encode($envArray));
$inputKey    = sha1(serialize([$code, $fileMap, $entryRel ?? '']));
$runKey      = sha1($inputKey . $envHash . pipelineVersion());

// Пробы: частичное выполнение кода проекта (метаданные Doctrine) → инварианты
if ($projectDir !== null && isset($environment->probes['doctrine'])) {
    $environment = $environment->withInvariants(DoctrineMetadataProbe::invariants(
        (string) realpath($projectDir),
        $environment->probes['doctrine'],
        $inputKey,
        __DIR__ . '/../var/cache/probes',
    ));
}

$runFile  = $runCacheDir . '/' . $runKey . '.run';
$lockFile = $runCacheDir . '/' . $runKey . '.lock';
if (!is_dir($runCacheDir)) {
    @mkdir($runCacheDir, 0777, true);
}

// Завершённый прогон отвечает из лёгкого файла (шаги и результат, без AST): полный
// .run после распаковки — сотни мегабайт, его десериализация занимает минуту
$doneFile = $runCacheDir . '/' . $runKey . '.done';
if (is_file($doneFile)) {
    $raw  = @gzuncompress((string) file_get_contents($doneFile));
    $done = $raw !== false ? @unserialize($raw, ['allowed_classes' => false]) : null;
    if (is_array($done)) {
        respond($done, 0, false, true);
    }
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
/**
 * Ответ клиенту: шаги начиная с $stepsFrom (клиент дописывает их к уже полученным).
 * Ответ больше RESPONSE_LIMIT — только шаги последнего прогона: унаследованные от
 * прогонов, с которых он продолжил (steps_base), не отправляются; steps_offset сообщает
 * клиенту номер первого отправленного шага.
 */
function respond(array $run, int $stepsFrom, bool $partial, bool $cached): never
{
    // Смещение, с которым клиент уже работает (он прислал его в запросе)
    $clientOffset = max(0, min((int) ($GLOBALS['input']['steps_offset'] ?? 0), $stepsFrom));
    $flags   = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;
    $payload = static fn(int $from, int $offset): array => [
        'original'      => $run['original'],
        'final'         => $run['final'] ?? null,
        'steps'         => array_slice($run['steps'], $from),
        'steps_from'    => $from,
        'steps_offset'  => $offset,
        'passes'        => $run['passes'],
        'partial'       => $partial,
        'cached'        => $cached,
        'total_time_ms' => $run['total_time_ms'],
    ];

    $base = (int) ($run['steps_base'] ?? 0);
    $json = (string) json_encode($payload($stepsFrom, $clientOffset), $flags);
    if ($stepsFrom < $base && strlen($json) > RESPONSE_LIMIT) {
        $json = (string) json_encode($payload($base, $base), $flags);
    }

    echo $json;
    exit;
}

if ($run !== null && $run['done']) {
    saveDone($doneFile, $run); // кэш прежней версии — лёгкий файл ещё не записан
    respond($run, 0, false, true);
}

$stepsFrom = max(0, min((int) ($input['steps_from'] ?? 0), count($run['steps'] ?? [])));

// fresh: true — прогон с исходного кода, без продолжения с прошлого результата
$runCache = new RunCache($runCacheDir);
if ($run === null && empty($input['fresh']) && ($old = $runCache->previous($inputKey, $envHash, $code)) !== null) {
    $run = [
        ...$old,
        'ast'           => $old['main_ast'],
        'main_ast'      => null,
        'idle_runs'     => 0,
        'visitor_index' => 0,
        // Шаги прошлых прогонов: при большом ответе клиенту отдаются только новые
        'steps_base'    => count($old['steps']),
        'done'          => false,
        'final'         => null,
    ];
}

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

$run['input_key'] = $inputKey;

/** Сохраняет состояние прогона (атомарно: через временный файл). */
/**
 * Снимает служебные ссылки (`parent`, `next`, `previous`, `origNode`) везде, где есть
 * узлы: в атрибутах, в хранилище областей видимости. Иначе через `parent` в файл
 * попадает весь граф, а unserialize с сотнями тысяч обратных ссылок (`r:N`) работает
 * квадратично — минута на загрузку прогона.
 *
 * @param \SplObjectStorage<object, true> $seen
 */
function stripDeep(mixed $value, \SplObjectStorage $seen): void
{
    if (is_array($value)) {
        foreach ($value as $item) {
            stripDeep($item, $seen);
        }

        return;
    }

    if (!is_object($value) || isset($seen[$value]) || $value instanceof \Closure) {
        return;
    }

    $seen[$value] = true;
    if ($value instanceof PhpParser\Node) {
        $attributes = $value->getAttributes();
        unset($attributes['parent'], $attributes['next'], $attributes['previous'], $attributes['origNode']);
        $value->setAttributes($attributes);
        foreach ($attributes as $attribute) {
            stripDeep($attribute, $seen);
        }

        foreach ($value->getSubNodeNames() as $name) {
            stripDeep($value->$name, $seen);
        }

        return;
    }

    for ($class = new \ReflectionObject($value); $class !== false; $class = $class->getParentClass()) {
        foreach ($class->getProperties() as $property) {
            if (!$property->isStatic() && $property->isInitialized($value)) {
                stripDeep($property->getValue($value), $seen);
            }
        }
    }
}

function saveRun(string $runFile, array $run): void
{
    $seen = new \SplObjectStorage();
    foreach (['ast', 'main_ast', 'scope_store'] as $key) {
        stripDeep($run[$key] ?? null, $seen);
    }

    $tmp        = $runFile . '.' . getmypid() . '.tmp';
    file_put_contents($tmp, gzcompress(serialize($run), 1));
    rename($tmp, $runFile);
    if (!empty($run['done'])) {
        saveDone(substr($runFile, 0, -strlen('.run')) . '.done', $run);
    }
}

/** Лёгкая копия завершённого прогона — только то, что отдаёт respond(). */
function saveDone(string $doneFile, array $run): void
{
    $light = array_intersect_key($run, array_flip(['original', 'final', 'steps', 'passes', 'total_time_ms', 'steps_base']));
    $tmp   = $doneFile . '.' . getmypid() . '.tmp';
    file_put_contents($tmp, gzcompress(serialize($light), 1));
    rename($tmp, $doneFile);
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
    try {
        $ast = array_values($t2->traverse($ast));
    } catch (Throwable $throwable) {
        // Имя правила в сообщении: иначе по стеку NodeTraverser виновника не найти
        throw new RuntimeException($visitor::class . ': ' . $throwable->getMessage(), 0, $throwable);
    }

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
    if ($attrs !== []) {
        /** @var VisitorMeta $m */
        $m = $attrs[0]->newInstance();
        return ['name' => $m->name ?: $ref->getShortName(), 'desc' => $m->desc];
    }

    return ['name' => $ref->getShortName(), 'desc' => ''];
}

// Состояние, которое переживает проходы (и запросы — через сохранённый прогон).
// Визиторы пересоздаются на каждом проходе, а кеш предвыполнения и enum-методов — нет
$pipeline = new VisitorPipeline(
    $run['scope_store'],
    $run['exec_cache'],
    new Sandbox($run['exec_cache']),
    $run['enum_cache'],
    $fileMap,
    $entryRel,
    $environment,
    $projectDir === null ? null : (string) realpath($projectDir),
);

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
$visitorCount = count($pipeline->main());
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

    foreach (array_slice($pipeline->main(), $startIndex, null, true) as $index => $visitor) {
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
// Копия AST основного цикла: следующая версия конвейера продолжит с неё (см. RunCache)
$run['main_ast']  = unserialize(serialize(stripConnections($ast)));
$readabilityStart = microtime(true);
$currentCode      = $printer->prettyPrint($ast);
$reconnect        = true;

foreach (VisitorPipeline::readability() as $visitor) {
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
$runCache->markLatest($inputKey, $envHash, $runKey);

respond($run, $stepsFrom, false, false);
