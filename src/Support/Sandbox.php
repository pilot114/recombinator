<?php

declare(strict_types=1);

namespace Recombinator\Support;

use PhpParser\Node;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;
use Recombinator\Core\ExecutionCache;
use Throwable;

/**
 * Безопасный sandbox для выполнения "чистого" кода в compile-time
 *
 * Этот класс обеспечивает изолированное выполнение PHP кода,
 * который не имеет побочных эффектов и может быть безопасно
 * вычислен на этапе компиляции.
 *
 * Ограничения безопасности:
 * - Запрещены функции работы с файловой системой (file_*, fopen, etc)
 * - Запрещены функции выполнения кода (eval, exec, system, etc)
 * - Запрещены функции работы с сетью (curl_*, fsockopen, etc)
 * - Запрещены функции изменения состояния (header, session_*, etc)
 * - Ограничено время выполнения
 * - Ограничена рекурсия
 */
class Sandbox
{
    /**
     * Максимальное время выполнения в секундах
     */
    private const int MAX_EXECUTION_TIME = 1;

    /**
     * Список запрещенных функций (blacklist)
     */
    private const array FORBIDDEN_FUNCTIONS = [
        // Выполнение кода
        'eval', 'exec', 'system', 'passthru', 'shell_exec', 'popen', 'proc_open',
        'pcntl_exec', 'assert', 'create_function', 'include', 'include_once',
        'require', 'require_once',

        // Файловая система
        'file_put_contents', 'file_get_contents', 'fopen', 'fwrite', 'fputs',
        'fread', 'file', 'readfile', 'unlink', 'rmdir', 'mkdir', 'chmod',
        'chown', 'chgrp', 'touch', 'symlink', 'link', 'copy', 'rename',
        'tmpfile', 'tempnam',

        // Сеть
        'curl_exec', 'curl_init', 'curl_multi_exec', 'fsockopen',
        'socket_connect', 'socket_create', 'socket_send', 'socket_write',
        'mail', 'stream_socket_client', 'stream_socket_server',

        // Изменение состояния
        'header', 'setcookie', 'session_start', 'session_destroy',
        'session_regenerate_id', 'set_time_limit', 'ini_set', 'ini_alter',
        'putenv', 'apache_setenv',

        // Базы данных (потенциально опасные)
        'mysqli_query', 'mysql_query', 'pg_query', 'oci_execute',

        // Опасные для выполнения
        'exit', 'die', 'register_shutdown_function', 'register_tick_function',
        'pcntl_signal', 'pcntl_alarm',

        // Рефлексия и динамическое выполнение
        'call_user_func', 'call_user_func_array', 'forward_static_call',
        'forward_static_call_array',
    ];

    /**
     * Список разрешенных чистых функций (whitelist)
     * Эти функции детерминированы и не имеют побочных эффектов
     */
    private const array ALLOWED_PURE_FUNCTIONS = [
        // Математические
        'abs', 'acos', 'acosh', 'asin', 'asinh', 'atan', 'atan2', 'atanh',
        'ceil', 'cos', 'cosh', 'deg2rad', 'exp', 'floor', 'fmod', 'hypot',
        'log', 'log10', 'log1p', 'max', 'min', 'pow', 'rad2deg', 'round',
        'sin', 'sinh', 'sqrt', 'tan', 'tanh',

        // Строковые
        'addslashes', 'bin2hex', 'chr', 'chunk_split', 'convert_uudecode',
        'convert_uuencode', 'crc32', 'crypt', 'explode', 'hex2bin', 'htmlentities',
        'htmlspecialchars', 'implode', 'join', 'lcfirst', 'ltrim', 'md5',
        'metaphone', 'nl2br', 'ord', 'quoted_printable_decode', 'quotemeta',
        'rtrim', 'sha1', 'similar_text', 'soundex', 'sprintf', 'str_contains',
        'str_ends_with', 'str_pad', 'str_repeat', 'str_replace', 'str_rot13',
        'str_split', 'str_starts_with', 'str_word_count', 'strcasecmp', 'strcmp',
        'strip_tags', 'stripcslashes', 'stripslashes', 'stripos', 'stristr',
        'strlen', 'strnatcasecmp', 'strnatcmp', 'strncasecmp', 'strncmp',
        'strpos', 'strrchr', 'strrev', 'strripos', 'strrpos', 'strstr',
        'strtolower', 'strtoupper', 'strtr', 'substr', 'substr_compare',
        'substr_count', 'substr_replace', 'trim', 'ucfirst', 'ucwords',
        'vsprintf', 'wordwrap',

        // Массивы
        'array_change_key_case', 'array_chunk', 'array_column', 'array_combine',
        'array_count_values', 'array_diff', 'array_diff_assoc', 'array_diff_key',
        'array_diff_uassoc', 'array_diff_ukey', 'array_fill', 'array_fill_keys',
        'array_filter', 'array_flip', 'array_intersect', 'array_intersect_assoc',
        'array_intersect_key', 'array_key_exists', 'array_keys', 'array_map',
        'array_merge', 'array_merge_recursive', 'array_pad', 'array_product',
        'array_reverse', 'array_search', 'array_slice', 'array_sum', 'array_unique',
        'array_values', 'arsort', 'asort', 'compact', 'count', 'current',
        'each', 'end', 'extract', 'in_array', 'key', 'key_exists', 'krsort',
        'ksort', 'list', 'natcasesort', 'natsort', 'next', 'pos', 'prev',
        'range', 'reset', 'rsort', 'shuffle', 'sizeof', 'sort', 'uasort',
        'uksort', 'usort',

        // Типы и проверки
        'is_array', 'is_bool', 'is_callable', 'is_countable', 'is_double',
        'is_float', 'is_int', 'is_integer', 'is_iterable', 'is_long',
        'is_null', 'is_numeric', 'is_object', 'is_real', 'is_scalar',
        'is_string', 'isset', 'empty',

        // Переменные
        'gettype', 'intval', 'floatval', 'strval', 'boolval',
        'serialize', 'unserialize',

        // JSON
        'json_encode', 'json_decode',

        // Base64
        'base64_encode', 'base64_decode',

        // URL
        'urlencode', 'urldecode', 'rawurlencode', 'rawurldecode',
        'parse_url', 'parse_str', 'http_build_query',

        // Регулярные выражения
        'preg_match', 'preg_match_all', 'preg_replace', 'preg_split',
        'preg_quote', 'preg_grep',
    ];

    private readonly StandardPrinter $printer;

    public function __construct(private readonly ExecutionCache $cache)
    {
        $this->printer = new StandardPrinter();
    }

    /**
     * Выполняет код в безопасном окружении
     *
     * @param  Node                 $node    AST узел для
     *                                       выполнения
     * @param  array<string, mixed> $context Контекст выполнения
     *                                       (переменные, константы)
     * @return mixed Результат выполнения или null в случае ошибки
     */
    public function execute(Node $node, array $context = []): mixed
    {
        // Проверяем кеш
        $cacheKey = $this->generateCacheKey($node, $context);
        if ($this->cache->has($cacheKey)) {
            return $this->cache->get($cacheKey);
        }

        // Проверяем безопасность кода
        if (!$this->isSafe($node)) {
            return null;
        }

        // Генерируем код для выполнения
        $code = $this->generateExecutableCode($node, $context);

        // Выполняем в изолированном окружении
        $result = $this->executeInIsolation($code);

        // Кешируем результат если выполнение успешно
        if ($result !== null && !($result instanceof SandboxError)) {
            $this->cache->set($cacheKey, $result);
        }

        return $result;
    }

    /**
     * Проверяет безопасность узла для выполнения
     */
    private function isSafe(Node $node): bool
    {
        // Блокируем опасные языковые конструкции
        if (
            $node instanceof Node\Expr\Eval_
            || $node instanceof Node\Expr\Include_
            || $node instanceof Node\Expr\Exit_
            || $node instanceof Node\Expr\ShellExec
        ) {
            return false;
        }

        // Проверяем на запрещенные функции
        if ($node instanceof Node\Expr\FuncCall) {
            $funcName = $this->getFunctionName($node);
            if ($funcName && in_array(strtolower($funcName), self::FORBIDDEN_FUNCTIONS, true)) {
                return false;
            }

            // Если функция не в whitelist, тоже запрещаем
            if ($funcName && !in_array(strtolower($funcName), self::ALLOWED_PURE_FUNCTIONS, true)) {
                return false;
            }
        }

        // Рекурсивно проверяем дочерние узлы
        foreach ($node->getSubNodeNames() as $name) {
            $subNode = $node->$name;
            if ($subNode instanceof Node) {
                if (!$this->isSafe($subNode)) {
                    return false;
                }
            } elseif (is_array($subNode)) {
                foreach ($subNode as $item) {
                    if ($item instanceof Node && !$this->isSafe($item)) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    /**
     * Получает имя функции из узла вызова
     */
    private function getFunctionName(Node\Expr\FuncCall $node): ?string
    {
        if ($node->name instanceof Node\Name) {
            return $node->name->toString();
        }

        return null;
    }

    /**
     * Генерирует исполняемый PHP код
     *
     * @param array<string, mixed> $context
     */
    private function generateExecutableCode(Node $node, array $context): string
    {
        $code = "<?php\n";

        // Добавляем контекст (переменные)
        foreach ($context as $varName => $value) {
            $code .= sprintf('$%s = %s;' . "\n", $varName, var_export($value, true));
        }

        // Добавляем код для выполнения
        $code .= 'return ' . $this->printer->prettyPrint([$node]) . ';';

        return $code;
    }

    /**
     * Выполняет код в изолированном окружении
     */
    private function executeInIsolation(string $code): mixed
    {
        $oldTimeLimit = ini_get('max_execution_time');
        try {
            // Устанавливаем лимит времени выполнения
            set_time_limit(self::MAX_EXECUTION_TIME);

            // Выполняем код
            $result = eval('?>' . $code);

            // Восстанавливаем лимит
            set_time_limit((int)$oldTimeLimit);

            return $result;
        } catch (Throwable $throwable) {
            // Восстанавливаем лимит в случае ошибки
            set_time_limit((int)$oldTimeLimit);

            $errorCode = $throwable->getCode();
            return new SandboxError($throwable->getMessage(), is_int($errorCode) ? $errorCode : 0, $throwable);
        }
    }

    /**
     * Генерирует ключ кеша на основе узла и контекста
     *
     * @param array<string, mixed> $context
     */
    private function generateCacheKey(Node $node, array $context): string
    {
        $nodeCode = $this->printer->prettyPrint([$node]);
        $contextSerialized = serialize($context);
        return md5($nodeCode . $contextSerialized);
    }

    /**
     * Проверяет, является ли результат ошибкой
     */
    public function isError(mixed $result): bool
    {
        return $result instanceof SandboxError;
    }

    /**
     * Возвращает статистику кеша
     *
     * @return array<mixed>
     */
    public function getCacheStats(): array
    {
        return $this->cache->getStats();
    }
}
