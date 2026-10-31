#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Сравнительные прогоны конвейера на примерах в трёх режимах выбора правил
 * (RuleScheduler: sequential, priority, estimate) и калибровка правил.
 *
 *   php bin/rule-stats.php [--write] [--modes=sequential,priority,estimate] [пример ...]
 *
 * Прогон sequential замеряет каждый запуск правила: время, сколько узлов убрано, оценку
 * до запуска. По сумме запусков на всех примерах — калибровка правила (RuleCalibration):
 * время на узел, доля убранных узлов среди кандидатов, приоритет по умолчанию = убранных
 * узлов в секунду. --write записывает её в src/Transformation/rule-calibration.php.
 * Оценка, обещавшая 0 кандидатов, при изменившем код запуске — ошибка targets правила.
 * Итоговый код каждого прогона — в var/rule-stats/<пример>.<режим>.php (для diff режимов),
 * все запуски правил — в var/rule-stats/runs.json.
 */

require __DIR__ . '/../vendor/autoload.php';

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NodeConnectingVisitor;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as Printer;
use Recombinator\Core\ExecutionCache;
use Recombinator\Core\TargetEnvironment;
use Recombinator\Domain\ScopeStore;
use Recombinator\Support\DoctrineMetadataProbe;
use Recombinator\Support\ProjectFiles;
use Recombinator\Support\Sandbox;
use Recombinator\Transformation\AstProfile;
use Recombinator\Transformation\RuleScheduler;
use Recombinator\Transformation\VisitorPipeline;
use Recombinator\Transformation\Visitor\BaseVisitor;
use Recombinator\Transformation\Visitor\VisitorMeta;

ini_set('memory_limit', '6G');

$write    = in_array('--write', $argv, true);
$modes    = array_reverse(RuleScheduler::MODES);
$examples = [];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--modes=')) {
        $modes = explode(',', substr($arg, strlen('--modes=')));
    } elseif ($arg !== '--write') {
        $examples[] = $arg;
    }
}

$examplesDir = dirname(__DIR__) . '/examples';
$allMeta     = json_decode((string) file_get_contents($examplesDir . '/meta.json'), true) ?? [];
$examples    = $examples !== [] ? $examples : array_map('basename', glob($examplesDir . '/*', GLOB_ONLYDIR) ?: []);

/**
 * Вход как в public/api.php: проект (src, vendor, корень), несколько файлов или один.
 *
 * @return array{code: string, fileMap: array<string, string>, entry: string, environment: TargetEnvironment, projectDir: ?string}
 */
function loadExample(string $dir, array $meta): array
{
    if (($meta['type'] ?? '') === 'project') {
        $projectDir = (string) realpath($dir);
        $entry      = (string) (is_array($meta['entry']) ? reset($meta['entry']) : $meta['entry']);
        $fileMap    = ProjectFiles::collect($projectDir);
        $fileMap[$entry] = (string) file_get_contents($projectDir . '/' . $entry);

        $environment = TargetEnvironment::fromArray(is_array($meta['environment'] ?? null) ? $meta['environment'] : [])->withProjectDir($projectDir);
        if (isset($environment->probes['doctrine'])) {
            $inputKey    = sha1(serialize([$fileMap[$entry], $fileMap, $entry]));
            $environment = $environment->withInvariants(DoctrineMetadataProbe::invariants($projectDir, $environment->probes['doctrine'], $inputKey, dirname(__DIR__) . '/var/cache/probes'));
        }

        return ['code' => $fileMap[$entry], 'fileMap' => $fileMap, 'entry' => $entry, 'environment' => $environment, 'projectDir' => $projectDir];
    }

    $files = [];
    foreach (glob($dir . '/*.php') ?: [] as $file) {
        $files[(string) preg_replace('/^\d+-/', '', basename($file))] = (string) file_get_contents($file);
    }

    if (count($files) === 1) {
        return ['code' => (string) reset($files), 'fileMap' => [], 'entry' => '', 'environment' => new TargetEnvironment(), 'projectDir' => null];
    }

    $entry = isset($files['index.php']) ? 'index.php' : (string) array_key_first($files);

    return ['code' => $files[$entry], 'fileMap' => $files, 'entry' => $entry, 'environment' => new TargetEnvironment(), 'projectDir' => null];
}

/**
 * @param array<Node> $ast
 */
function apply(array &$ast, NodeVisitorAbstract $visitor, bool $reconnect): void
{
    if ($reconnect) {
        $ast = array_values(new NodeTraverser(new NodeConnectingVisitor())->traverse($ast));
    }

    $ast = array_values(new NodeTraverser($visitor)->traverse($ast));
}

/**
 * Прогон до неподвижной точки и фаза читаемости — как в public/api.php.
 * Каждый запуск правила записывается в $log; размер AST, оценка и убранные узлы — только
 * в режиме sequential (замеры не входят в его время).
 *
 * @param  list<array{example: string, mode: string, rule: string, size: ?int, candidates: ?int, nodes: ?int, timeMs: float, delta: ?int, changed: bool}> $log
 * @return array{timeMs: float, scheduleMs: float, runs: int, skipped: int, steps: int, passes: int, final: string}
 */
function runPipeline(string $example, array $input, string $mode, array &$log): array
{
    $printer   = new Printer();
    $execCache = new ExecutionCache();
    $pipeline  = new VisitorPipeline(new ScopeStore(), $execCache, new Sandbox($execCache), new ArrayObject(), $input['fileMap'], $input['entry'], $input['environment'], $input['projectDir']);
    $code      = str_starts_with(ltrim($input['code']), '<?') ? $input['code'] : "<?php\n" . $input['code'];
    $ast       = new ParserFactory()->createForNewestSupportedVersion()->parse($code) ?? [];
    $scheduler = new RuleScheduler($mode, count($pipeline->main()));
    $measure   = $mode === RuleScheduler::SEQUENTIAL;

    $start     = microtime(true);
    $steps     = 0;
    $current   = $printer->prettyPrint($ast);
    $reconnect = true;
    $profile   = $measure ? new AstProfile($ast) : null;
    $overhead  = 0.0; // время замеров sequential не входит в его время
    $schedule  = 0.0; // время выбора правил, включая оценки

    while (true) {
        $s0    = microtime(true);
        $index = $scheduler->next($rules = $pipeline->main(), $ast);
        $schedule += microtime(true) - $s0;
        if ($index === null) {
            break;
        }

        $visitor  = $rules[$index];
        $m0       = microtime(true);
        $estimate = $profile !== null && $visitor instanceof BaseVisitor ? $visitor->estimate($profile) : null;
        $targets  = $profile !== null ? $profile->match(VisitorMeta::of($visitor)->targets) : [0, 0];
        $overhead += microtime(true) - $m0;

        $t0 = microtime(true);
        apply($ast, $visitor, $reconnect);
        $timeMs = (microtime(true) - $t0) * 1000;

        $previous  = $current;
        $current   = $printer->prettyPrint($ast);
        $changed   = $previous !== $current;
        $reconnect = $changed;
        $scheduler->done($index, $changed);
        $steps += (int) $changed;

        $run = ['example' => $example, 'mode' => $mode, 'rule' => $visitor::class, 'size' => null, 'candidates' => null, 'nodes' => null, 'timeMs' => $timeMs, 'delta' => null, 'changed' => $changed];
        if ($profile !== null) {
            $m0       = microtime(true);
            $before   = $profile->size;
            $profile  = $changed ? new AstProfile($ast) : $profile;
            $overhead += microtime(true) - $m0;
            $run      = ['size' => $before, 'candidates' => $estimate?->candidates, 'nodes' => $targets[1], 'delta' => $before - $profile->size] + $run;
        }

        $log[] = $run;
    }

    foreach (VisitorPipeline::readability() as $visitor) {
        apply($ast, $visitor, true);
    }

    return [
        'timeMs'     => (microtime(true) - $start - $overhead) * 1000,
        'scheduleMs' => $schedule * 1000,
        'runs'    => $scheduler->runs(),
        'skipped' => $scheduler->skipped,
        'steps'   => $steps,
        'passes'  => $scheduler->pass,
        'final'   => "<?php\n" . $printer->prettyPrint($ast),
    ];
}

// ── Сравнительные прогоны ────────────────────────────────────────────────────

$log     = [];
$finalDir = dirname(__DIR__) . '/var/rule-stats';
is_dir($finalDir) || mkdir($finalDir, 0777, true);
printf("%-18s %-10s %10s %9s %6s %7s %6s %6s %8s  %s\n", 'example', 'mode', 'time ms', 'sched ms', 'runs', 'skipped', 'steps', 'passes', 'lines', 'final');
foreach ($examples as $example) {
    $input = loadExample($examplesDir . '/' . $example, $allMeta[$example] ?? []);
    foreach ($modes as $mode) {
        $r = runPipeline($example, $input, $mode, $log);
        file_put_contents("{$finalDir}/{$example}.{$mode}.php", $r['final']);
        printf(
            "%-18s %-10s %10.0f %9.0f %6d %7d %6d %6d %8d  %s\n",
            $example,
            $mode,
            $r['timeMs'],
            $r['scheduleMs'],
            $r['runs'],
            $r['skipped'],
            $r['steps'],
            $r['passes'],
            substr_count($r['final'], "\n"),
            substr(sha1($r['final']), 0, 8),
        );
    }
}

file_put_contents($finalDir . '/runs.json', json_encode($log));

// ── Нарушения оценки: правило изменило код, хотя оценка обещала 0 кандидатов ───

$violations = array_filter($log, static fn(array $run): bool => $run['changed'] && $run['candidates'] === 0);
foreach ($violations as $run) {
    printf("VIOLATION %s: changed code with 0 candidates (AST %d nodes)\n", $run['rule'], $run['size']);
}

// ── Калибровка ───────────────────────────────────────────────────────────────

$log = array_filter($log, static fn(array $run): bool => $run['mode'] === RuleScheduler::SEQUENTIAL);
if ($log === []) {
    exit($violations === [] ? 0 : 1);
}

$byRule = [];
foreach ($log as $run) {
    $s = $byRule[$run['rule']] ?? ['runs' => 0, 'changed' => 0, 'size' => 0, 'nodes' => 0, 'timeMs' => 0.0, 'delta' => 0];
    $s['runs']++;
    $s['changed'] += (int) $run['changed'];
    $s['size']    += $run['size'];
    $s['nodes']   += $run['nodes'];
    $s['timeMs']  += $run['timeMs'];
    $s['delta']   += $run['delta'];
    $byRule[$run['rule']] = $s;
}

$calibration = [];
foreach ($byRule as $rule => $s) {
    $calibration[$rule] = [
        'priority'  => (int) round($s['delta'] / max($s['timeMs'], 0.001) * 1000),
        'msPerNode' => round($s['timeMs'] / max($s['size'], 1), 6),
        'yield'     => $s['nodes'] > 0 ? round($s['delta'] / $s['nodes'], 6) : 0.0,
    ];
}

uasort($calibration, static fn(array $a, array $b): int => $b['priority'] <=> $a['priority']);
printf("\n%-40s %9s %6s %7s %10s %9s %10s\n", 'rule', 'priority', 'runs', 'changed', 'time ms', 'removed', 'yield');
foreach ($calibration as $rule => $c) {
    $s = $byRule[$rule];
    printf("%-40s %9d %6d %7d %10.0f %9d %10.6f\n", substr(strrchr($rule, '\\') ?: $rule, 1), $c['priority'], $s['runs'], $s['changed'], $s['timeMs'], $s['delta'], $c['yield']);
}

if ($write) {
    $lines = [];
    foreach ($calibration as $rule => $c) {
        $lines[] = sprintf("    \\%s::class => ['priority' => %d, 'msPerNode' => %s, 'yield' => %s],", $rule, $c['priority'], var_export($c['msPerNode'], true), var_export($c['yield'], true));
    }

    file_put_contents(
        dirname(__DIR__) . '/src/Transformation/rule-calibration.php',
        "<?php\n\ndeclare(strict_types=1);\n\n// Сгенерировано bin/rule-stats.php --write (" . implode(', ', $examples) . "): правка руками перезапишется при следующей калибровке\nreturn [\n" . implode("\n", $lines) . "\n];\n",
    );
    echo "\nКалибровка записана в src/Transformation/rule-calibration.php\n";
}

exit($violations === [] ? 0 : 1);
