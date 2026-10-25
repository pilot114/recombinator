<?php

/**
 * Воркер для SandboxRunner: принимает задачи из STDIN и выполняет каждую в отдельном форке
 *
 * Запускается один раз как `php -n` и живёт, пока открыт STDIN. Задача в форке уже запущенного
 * интерпретатора стоит ~2,5 мс против ~8 мс на старт нового процесса, а изоляция задач
 * сохраняется: классы и функции, объявленные одной задачей, не видны следующей.
 *
 * Протокол:
 * - при старте: "READY\n" или "NOFORK\n" (нет pcntl — воркер завершается)
 * - запрос:     "<длина кода> <таймаут, с> <memory_limit>\n<код, начиная с <?php>"
 * - ответ:      "<статус> <длина>\n<данные>"; статус 0 — данные это вывод кода,
 *               1 — текст ошибки, 2 — превышен таймаут
 *
 * Код выполняется через eval('?>' . $code) — так же, как если бы был запущен файлом.
 */

declare(strict_types=1);

if (!function_exists('pcntl_fork') || !function_exists('stream_socket_pair')) {
    fwrite(STDOUT, "NOFORK\n");
    exit(1);
}

fwrite(STDOUT, "READY\n");

// Маркер ошибки в потоке вывода форка: json_encode экранирует \0, поэтому с выводом не пересекается
const SANDBOX_ERROR_MARKER = "\0ERR";

$respond = static function (int $status, string $payload): void {
    fwrite(STDOUT, $status . ' ' . strlen($payload) . "\n" . $payload);
};

while (($header = fgets(STDIN)) !== false) {
    [$length, $timeout, $memoryLimit] = array_pad(explode(' ', trim($header)), 3, '');
    $code = (int) $length > 0 ? (string) stream_get_contents(STDIN, (int) $length) : '';

    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    $pid  = $pair === false ? -1 : pcntl_fork();
    if ($pair === false || $pid === -1) {
        $respond(1, 'Failed to fork sandbox worker');
        continue;
    }

    if ($pid === 0) {
        // Форк: весь вывод уходит в сокет, STDIN/STDOUT воркера закрыты, чтобы код задачи
        // не мог испортить протокол
        fclose($pair[0]);
        $out = $pair[1];
        fclose(STDIN);
        fclose(STDOUT);

        ini_set('memory_limit', $memoryLimit !== '' ? $memoryLimit : '32M');
        // Обработчика SIGALRM нет — по умолчанию сигнал завершает процесс
        pcntl_alarm(max(1, (int) $timeout));

        $fail = static function (string $message) use ($out): void {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            fwrite($out, SANDBOX_ERROR_MARKER . $message);
        };

        ob_start(static function (string $buffer, int $phase) use ($out): string {
            if (($phase & PHP_OUTPUT_HANDLER_CLEAN) === 0) {
                fwrite($out, $buffer);
            }

            return '';
        });

        register_shutdown_function(static function () use ($fail): void {
            $error = error_get_last();
            if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                $fail($error['message']);
            }
        });

        try {
            eval('?>' . $code);
        } catch (Throwable $throwable) {
            $fail($throwable::class . ': ' . $throwable->getMessage());
            exit(255);
        }

        exit(0);
    }

    fclose($pair[1]);
    $output = (string) stream_get_contents($pair[0]);
    fclose($pair[0]);
    pcntl_waitpid($pid, $status);

    if (pcntl_wifsignaled($status) && pcntl_wtermsig($status) === SIGALRM) {
        $respond(2, 'Execution timed out after ' . $timeout . ' seconds');
        continue;
    }

    $errorPos = strpos($output, SANDBOX_ERROR_MARKER);
    if ($errorPos !== false) {
        $respond(1, substr($output, $errorPos + strlen(SANDBOX_ERROR_MARKER)));
        continue;
    }

    if (!pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
        $respond(1, 'Subprocess execution failed');
        continue;
    }

    $respond(0, $output);
}
