<?php

declare(strict_types=1);

use Recombinator\Support\SandboxError;
use Recombinator\Support\SandboxRunner;

beforeEach(
    function (): void {
        $this->runner = new SandboxRunner();
    }
);

/**
 * Отключает воркер: каждая задача выполняется отдельным процессом
 */
function withoutWorker(SandboxRunner $runner): SandboxRunner
{
    new ReflectionProperty($runner, 'workerUnavailable')->setValue($runner, true);

    return $runner;
}

dataset(
    'runner modes',
    [
        'worker'  => [fn(): SandboxRunner => new SandboxRunner()],
        'process' => [fn(): SandboxRunner => withoutWorker(new SandboxRunner())],
    ]
);

it(
    'returns output of executed code',
    function (Closure $make): void {
        expect($make()->run('<?php echo json_encode(["v" => strtolower("Rex")]);', 2, '32M'))->toBe('{"v":"rex"}');
    }
)->with('runner modes');

it(
    'reports uncaught exceptions as errors',
    function (Closure $make): void {
        $result = $make()->run('<?php throw new RuntimeException("boom");', 2, '32M');

        expect($result)->toBeInstanceOf(SandboxError::class)
            ->and($result->getMessage())->toContain('boom')
            ->and($result->isTimeout())->toBeFalse();
    }
)->with('runner modes');

it(
    'treats non-zero exit as failure',
    function (Closure $make): void {
        expect($make()->run('<?php echo "partial"; exit(3);', 2, '32M'))->toBeInstanceOf(SandboxError::class);
    }
)->with('runner modes');

it(
    'enforces memory limit',
    function (Closure $make): void {
        $result = $make()->run('<?php $a = str_repeat("x", 64 * 1024 * 1024); echo strlen($a);', 2, '32M');

        expect($result)->toBeInstanceOf(SandboxError::class)
            ->and($result->getMessage())->toContain('memory');
    }
)->with('runner modes');

it(
    'stops code that exceeds timeout',
    function (Closure $make): void {
        $result = $make()->run('<?php while (true) {}', 1, '32M');

        expect($result)->toBeInstanceOf(SandboxError::class)
            ->and($result->isTimeout())->toBeTrue();
    }
)->with('runner modes');

it(
    'isolates declarations between tasks',
    function (): void {
        expect($this->runner->run('<?php class SandboxRunnerFoo { const X = 1; } echo SandboxRunnerFoo::X;', 2, '32M'))->toBe('1')
            ->and($this->runner->run('<?php class SandboxRunnerFoo { const X = 2; } echo SandboxRunnerFoo::X;', 2, '32M'))->toBe('2');
    }
);

it(
    'does not let task code write into worker protocol',
    function (): void {
        expect($this->runner->run('<?php fwrite(STDOUT, "1 999\n"); echo "after";', 2, '32M'))->toBeInstanceOf(SandboxError::class)
            ->and($this->runner->run('<?php echo "still alive";', 2, '32M'))->toBe('still alive');
    }
)->skip(!function_exists('pcntl_fork'), 'нужен pcntl: без него воркера нет');

it(
    'restarts killed worker',
    function (): void {
        expect($this->runner->run('<?php echo "a";', 2, '32M'))->toBe('a');

        $process = new ReflectionProperty($this->runner, 'process')->getValue($this->runner);
        posix_kill(proc_get_status($process)['pid'], SIGKILL);
        usleep(50_000);

        expect($this->runner->run('<?php echo "b";', 2, '32M'))->toBe('b')
            ->and($this->runner->run('<?php echo "c";', 2, '32M'))->toBe('c');
    }
)->skip(!function_exists('posix_kill') || !function_exists('pcntl_fork'), 'нужны posix и pcntl');

it(
    'blocks file writes, processes and network even through callbacks',
    function (Closure $make): void {
        $file = tempnam(sys_get_temp_dir(), 'rcb_victim');
        $path = var_export($file, true);
        $runner = $make();

        expect($runner->run("<?php echo json_encode(array_map('unlink', [{$path}]));", 2, '32M'))->toBeInstanceOf(SandboxError::class)
            ->and($runner->run("<?php echo (int) @('un' . 'link')({$path});", 2, '32M'))->toBeInstanceOf(SandboxError::class)
            ->and($runner->run("<?php echo json_encode(array_map('shell_exec', ['echo hi']));", 2, '32M'))->toBeInstanceOf(SandboxError::class)
            ->and($runner->run("<?php echo json_encode(array_map('file_exists', ['/etc/hostname']));", 2, '32M'))->toBeInstanceOf(SandboxError::class)
            ->and($runner->run("<?php echo (string) @file_get_contents('http://127.0.0.1:1/');", 2, '32M'))->toBeInstanceOf(SandboxError::class)
            ->and(file_exists($file))->toBeTrue();

        unlink($file);
    }
)->with('runner modes');

it(
    'runs code that starts with declare(strict_types=1)',
    function (Closure $make): void {
        expect($make()->run("<?php\ndeclare(strict_types=1);\nfunction f(int \$x) { return \$x; }\ntry { f('1'); } catch (TypeError) { echo 'strict'; }", 2, '32M'))->toBe('strict');
    }
)->with('runner modes');
