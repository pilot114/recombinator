<?php

declare(strict_types=1);

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NodeConnectingVisitor;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as Printer;
use Recombinator\Core\TargetEnvironment;
use Recombinator\Transformation\Visitor\HeapSnapshotVisitor;

/** @return array{string, string, string, string} [результат, причина, вывод оригинала, вывод результата] */
function snapshot(string $code): array
{
    $project = sys_get_temp_dir() . '/rcb_snapshot_' . uniqid();
    mkdir($project . '/public', 0777, true);
    $ast     = new ParserFactory()->createForNewestSupportedVersion()->parse($code) ?? [];
    $ast     = new NodeTraverser(new NodeConnectingVisitor())->traverse($ast);

    $env     = TargetEnvironment::fromArray(['php' => PHP_VERSION, 'os' => PHP_OS_FAMILY, 'extensions' => get_loaded_extensions()]);
    $visitor = new HeapSnapshotVisitor($env, $project, 'public/index.php', $project . '/cache');
    $result  = new Printer()->prettyPrintFile(new NodeTraverser($visitor)->traverse($ast));
    $run     = static function (string $source) use ($project): string {
        file_put_contents($project . '/public/run.php', $source);

        return (string) shell_exec('cd ' . escapeshellarg($project . '/public') . ' && REQUEST_URI=/users ' . escapeshellarg(PHP_BINARY) . ' run.php 2>&1');
    };
    $outputs = [$run($code), $run($result)];
    shell_exec('rm -rf ' . escapeshellarg($project));

    return [$result, $visitor->lastReason(), ...$outputs];
}

it('replaces request-independent setup with hydration', function (): void {
    [$result, $reason, $before, $after] = snapshot('<?php
        class Container { private array $entries; public function __construct(array $s) { $this->entries = $s; }
            public function set(string $id, $v): void { $this->entries[$id] = $v; }
            public function get(string $id) { $e = $this->entries[$id]; return $e instanceof Closure ? $e($this) : $e; } }
        abstract class Base { protected array $routes = []; private int $count = 0;
            public function map(string $p, string $h): void { $this->routes[$p] = $h; $this->count++; } public function count(): int { return $this->count; } }
        final class App extends Base { public static int $apps = 0; public function __construct(private readonly Container $c) { self::$apps++; }
            public function run(): void { $h = $this->routes[$_SERVER["REQUEST_URI"]] ?? "none"; echo $h, ":", $this->c->get($h), ":", $this->count(), ":", self::$apps, "\n"; } }
        enum Mode { case Dev; case Prod; }
        define("X_MODE", "dev");
        $cnt = new Container(["mode" => Mode::Dev]);
        $cnt->set("list", static fn($c) => "users-" . $c->get("mode")->name);
        $app = new App($cnt);
        $app->map("/users", "list");
        $app->run();');

    expect($reason)->toStartWith('hydrated')
        ->and($result)->toContain('newInstanceWithoutConstructor')->toContain('define("X_MODE", "dev")')
        ->not->toContain('$app->map(')
        ->and($after)->toBe($before)->toBe("list:users-Dev:1:1\n");
});

it('stops before effects and keeps them', function (): void {
    [$result, $reason, $before, $after] = snapshot('<?php
        class Log { public array $lines = []; public function add(string $l): void { $this->lines[] = $l; } }
        $log = new Log(); $log->add("a");
        $log->add(date("Y") > 2000 ? "b" : "c");
        echo count($log->lines);');

    expect($result)->toContain('$log->add(date(')->not->toContain('$log->add("a")')
        ->and($after)->toBe($before)->toBe('2');
});

it('warms up lazy container entries and drops warm-ups with effects', function (): void {
    $code = '<?php
        class Container { private array $e = []; public function set(string $id, Closure $f): void { $this->e[$id] = $f; }
            public function get(string $id) { if ($this->e[$id] instanceof Closure) { $this->e[$id] = ($this->e[$id])($this); } return $this->e[$id]; } }
        class Em { public array $meta; public function __construct() { $this->meta = ["users" => strtoupper("id")]; } }
        class Clock { public int $t; public function __construct() { $this->t = time(); } }
        $c = new Container();
        $c->set("em", static fn($c) => new Em());
        $c->set("clock", static fn($c) => new Clock());
        echo $c->get("em")->meta["users"], $_SERVER["REQUEST_URI"] ?? "", "\n";';
    $project = sys_get_temp_dir() . '/rcb_snapshot_' . uniqid();
    mkdir($project . '/public', 0777, true);
    $ast     = new ParserFactory()->createForNewestSupportedVersion()->parse($code) ?? [];
    $ast     = new NodeTraverser(new NodeConnectingVisitor())->traverse($ast);

    $env     = TargetEnvironment::fromArray([
        'php' => PHP_VERSION, 'os' => PHP_OS_FAMILY, 'extensions' => get_loaded_extensions(),
        'warmup' => ['$c->get("em")', '$c->get("clock")'],
    ]);
    $visitor = new HeapSnapshotVisitor($env, $project, 'public/index.php', $project . '/cache');
    $result  = new Printer()->prettyPrintFile(new NodeTraverser($visitor)->traverse($ast));
    file_put_contents($project . '/public/run.php', $result);
    $output = (string) shell_exec('cd ' . escapeshellarg($project . '/public') . ' && ' . escapeshellarg(PHP_BINARY) . ' run.php 2>&1');
    shell_exec('rm -rf ' . escapeshellarg($project));

    expect($visitor->lastReason())->toContain('warmup failed')->toContain('time')
        ->and($result)->toContain('newInstanceWithoutConstructor')->toContain("\\Em::class")->toContain("'users' => 'ID'")
        ->toContain('static fn($c) => new Clock()')
        ->and($output)->toBe("ID\n");
});
