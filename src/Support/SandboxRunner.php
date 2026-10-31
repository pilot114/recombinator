<?php

declare(strict_types=1);

namespace Recombinator\Support;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Выполняет PHP-код в изолированном процессе и возвращает его вывод
 *
 * Держит один долгоживущий воркер (`php -n sandbox-worker.php`), который выполняет
 * каждую задачу в собственном форке. Если pcntl недоступен или воркер не запустился,
 * каждая задача выполняется отдельным процессом `php -n`.
 *
 * `-n` (без php.ini): расширения из конфига (grpc, gd, curl…) не нужны для чистых
 * вычислений, а их загрузка в 4–5 раз замедляет старт интерпретатора.
 */
final class SandboxRunner
{
    /**
     * Запас сверх таймаута задачи, после которого воркер считается зависшим
     */
    private const int WORKER_GRACE_SECONDS = 3;

    /**
     * Запуск процессов и сеть: проверка Sandbox::isSafe не видит вызовов через callback,
     * методы и собранные имена, поэтому запрет — в самом процессе
     */
    private const array DISABLED_FUNCTIONS = [
        'exec', 'system', 'passthru', 'shell_exec', 'popen', 'proc_open', 'pcntl_exec', 'posix_kill',
        'mail', 'fsockopen', 'pfsockopen', 'stream_socket_client', 'stream_socket_server',
        'socket_create', 'socket_connect', 'putenv', 'dl',
    ];

    private static ?self $shared = null;

    /**
     * @var resource|null
     */
    private $process;

    /**
     * @var resource|null
     */
    private $stdin;

    /**
     * @var resource|null
     */
    private $stdout;

    private bool $workerUnavailable = false;

    public function __construct(private readonly string $phpBinary = 'php')
    {
    }

    /**
     * Общий экземпляр на процесс: воркер запускается один раз
     */
    public static function shared(): self
    {
        return self::$shared ??= new self();
    }

    public function __destruct()
    {
        $this->stopWorker();
    }

    /**
     * Выполняет код (полный файл, начиная с `<?php`) и возвращает его вывод
     */
    public function run(string $code, int $timeout, string $memoryLimit): string|SandboxError
    {
        if ($this->ensureWorker()) {
            $result = $this->runInWorker($code, $timeout, $memoryLimit);
            if ($result !== null) {
                return $result;
            }
        }

        return $this->runInProcess($code, $timeout, $memoryLimit);
    }

    /**
     * @return string|SandboxError|null null — воркер сломался, задачу нужно выполнить иначе
     */
    private function runInWorker(string $code, int $timeout, string $memoryLimit): string|SandboxError|null
    {
        if ($this->stdin === null || $this->stdout === null) {
            return null;
        }

        $request = strlen($code) . ' ' . $timeout . ' ' . $memoryLimit . "\n" . $code;
        if (@fwrite($this->stdin, $request) !== strlen($request)) {
            $this->stopWorker();
            return null;
        }

        $header = $this->readLine($timeout + self::WORKER_GRACE_SECONDS);
        if ($header === null || preg_match('/^(\d) (\d+)$/', $header, $m) !== 1) {
            // Воркер завис или умер: перезапустится на следующей задаче
            $this->stopWorker();
            return $this->timeoutError($timeout);
        }

        $payload = (int) $m[2] > 0 ? (string) stream_get_contents($this->stdout, (int) $m[2]) : '';

        return match ($m[1]) {
            '0'     => $payload,
            '2'     => $this->timeoutError($timeout),
            default => new SandboxError($payload !== '' ? $payload : 'Subprocess execution failed'),
        };
    }

    private function timeoutError(int $timeout): SandboxError
    {
        return new SandboxError('Execution timed out after ' . $timeout . ' seconds', SandboxError::CODE_TIMEOUT);
    }

    private function runInProcess(string $code, int $timeout, string $memoryLimit): string|SandboxError
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'sandbox_');
        if ($tmpFile === false) {
            return new SandboxError('Failed to create temporary file');
        }

        file_put_contents($tmpFile, $code);

        try {
            $process = new Process(
                [$this->phpBinary, '-n', ...$this->isolation($tmpFile), '-d', 'display_errors=stderr', '-d', 'memory_limit=' . $memoryLimit, $tmpFile],
                timeout: $timeout
            );
            $process->run();

            if (!$process->isSuccessful()) {
                $errorOutput = $process->getErrorOutput();
                return new SandboxError($errorOutput ?: 'Subprocess execution failed');
            }

            return $process->getOutput();
        } catch (ProcessTimedOutException) {
            return $this->timeoutError($timeout);
        } catch (Throwable $throwable) {
            $errorCode = $throwable->getCode();
            return new SandboxError($throwable->getMessage(), is_int($errorCode) ? $errorCode : 0, $throwable);
        } finally {
            @unlink($tmpFile);
        }
    }

    /**
     * Запускает воркер при первой задаче; false — работаем без него
     */
    private function ensureWorker(): bool
    {
        if ($this->process !== null) {
            if (proc_get_status($this->process)['running']) {
                return true;
            }

            // Воркер умер между задачами — запускаем новый
            $this->stopWorker();
        }

        if ($this->workerUnavailable) {
            return false;
        }

        $process = @proc_open(
            // -n отключает и модули из ini: pcntl в Debian/Docker — отдельный модуль, без него форков нет
            [$this->phpBinary, '-n', '-d', 'extension=pcntl', ...$this->isolation(__DIR__ . '/sandbox-worker.php'), '-d', 'display_errors=stderr', __DIR__ . '/sandbox-worker.php'],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['file', DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null', 'w'],
            ],
            $pipes
        );
        if ($process === false) {
            $this->workerUnavailable = true;
            return false;
        }

        $this->process = $process;
        $this->stdin   = $pipes[0];
        $this->stdout  = $pipes[1];

        if ($this->readLine(self::WORKER_GRACE_SECONDS) !== 'READY') {
            $this->stopWorker();
            $this->workerUnavailable = true;
            return false;
        }

        return true;
    }

    /**
     * Флаги php: из файлов доступен только сам запускаемый скрипт (open_basedir — префикс
     * пути), обращение к остальным — ошибка задачи (sandbox-prepend.php); без URL-обёрток,
     * процессов и сети
     *
     * @return list<string>
     */
    private function isolation(string $script): array
    {
        $prepend = __DIR__ . '/sandbox-prepend.php';

        return [
            '-d', 'auto_prepend_file=' . $prepend,
            '-d', 'open_basedir=' . $script . PATH_SEPARATOR . $prepend,
            '-d', 'allow_url_fopen=0',
            '-d', 'disable_functions=' . implode(',', self::DISABLED_FUNCTIONS),
        ];
    }

    private function stopWorker(): void
    {
        foreach ([$this->stdin, $this->stdout] as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        if (is_resource($this->process)) {
            // Закрытый STDIN завершает исправный воркер; зависший добиваем
            proc_terminate($this->process, 9);
            proc_close($this->process);
        }

        $this->process = null;
        $this->stdin   = null;
        $this->stdout  = null;
    }

    private function readLine(int $timeoutSeconds): ?string
    {
        if ($this->stdout === null) {
            return null;
        }

        $read   = [$this->stdout];
        $write  = null;
        $except = null;
        if (@stream_select($read, $write, $except, $timeoutSeconds) !== 1) {
            return null;
        }

        $line = fgets($this->stdout);

        return $line === false ? null : rtrim($line, "\n");
    }
}
