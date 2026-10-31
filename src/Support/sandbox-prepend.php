<?php

/**
 * Подключается перед кодом песочницы (auto_prepend_file, см. SandboxRunner::isolation)
 *
 * Обращение к файлу вне open_basedir (и к URL при allow_url_fopen=0) PHP отдаёт как предупреждение и false — даже под `@`.
 * Здесь оно становится ошибкой задачи: иначе `array_map('file_exists', [...])` вернул бы
 * результат, который свернулся бы в константу вместо настоящей проверки.
 */

declare(strict_types=1);

set_error_handler(static function (int $errno, string $message): bool {
    if (str_contains($message, 'open_basedir restriction in effect') || str_contains($message, 'allow_url_fopen=0')) {
        throw new ErrorException($message, 0, $errno);
    }

    return false;
});
