<?php

declare(strict_types=1);

namespace Recombinator\Analysis;

use PhpParser\Node;
use Recombinator\Domain\SideEffectType;

/**
 * Классификатор побочных эффектов для PHP кода
 *
 * Анализирует узлы AST и определяет тип побочного эффекта
 * для каждого узла и его потомков.
 */
class SideEffectClassifier implements \Recombinator\Contract\EffectClassifierInterface
{
    /**
     * Функции I/O операций
     */
    private const array IO_FUNCTIONS = [
        // Вывод в консоль
        'echo', 'print', 'printf', 'vprintf', 'sprintf', 'fprintf',
        'var_dump', 'var_export', 'print_r', 'debug_zval_dump',
        'debug_print_backtrace',

        // Файловая система - чтение
        'file_get_contents', 'file', 'readfile', 'fopen', 'fread',
        'fgets', 'fgetss', 'fgetc', 'fgetcsv', 'fscanf',

        // Файловая система - запись
        'file_put_contents', 'fwrite', 'fputs', 'fputcsv',

        // Файловая система - операции
        'unlink', 'rmdir', 'mkdir', 'chmod', 'chown', 'chgrp',
        'touch', 'symlink', 'link', 'copy', 'rename',
        'tmpfile', 'tempnam', 'fclose', 'fflush',

        // Директории
        'opendir', 'readdir', 'closedir', 'rewinddir', 'scandir',
        'glob', 'is_dir', 'is_file', 'is_link', 'is_readable',
        'is_writable', 'is_executable', 'file_exists',

        // Потоки
        'stream_get_contents', 'stream_copy_to_stream',
    ];

    /**
     * Функции работы с базой данных
     */
    private const array DATABASE_FUNCTIONS = [
        // MySQLi
        'mysqli_query', 'mysqli_execute', 'mysqli_prepare',
        'mysqli_stmt_execute', 'mysqli_multi_query',

        // MySQL (deprecated)
        'mysql_query', 'mysql_db_query', 'mysql_unbuffered_query',

        // PostgreSQL
        'pg_query', 'pg_execute', 'pg_prepare', 'pg_query_params',

        // Oracle
        'oci_execute', 'oci_parse', 'oci_statement_type',

        // SQLite
        'sqlite_query', 'sqlite_exec', 'sqlite_array_query',

        // PDO обычно через объекты, но может быть и напрямую
    ];

    /**
     * Функции HTTP запросов
     */
    private const array HTTP_FUNCTIONS = [
        // cURL
        'curl_exec', 'curl_init', 'curl_multi_exec',

        // Сокеты
        'fsockopen', 'socket_connect', 'socket_create', 'socket_send',
        'socket_write', 'socket_read', 'socket_recv',

        // Потоки
        'stream_socket_client', 'stream_socket_server',
        'stream_socket_accept', 'stream_socket_sendto',

        // Email
        'mail',

        // HTTP обёртки для file_get_contents обрабатываются отдельно
    ];

    /**
     * Недетерминированные функции
     */
    private const array NON_DETERMINISTIC_FUNCTIONS = [
        // Случайные числа
        'rand', 'mt_rand', 'random_int', 'random_bytes',
        'srand', 'mt_srand', 'shuffle', 'array_rand',

        // Время
        'time', 'microtime', 'date', 'gmdate', 'strtotime',
        'gettimeofday', 'localtime', 'getdate', 'idate',

        // Уникальные ID
        'uniqid', 'com_create_guid',

        // Процессы
        'getmypid', 'getmyuid', 'getmygid', 'getlastmod',
    ];

    /**
     * Функции изменения глобального состояния
     */
    private const array GLOBAL_STATE_FUNCTIONS = [
        // PHP конфигурация
        'ini_set', 'ini_alter', 'ini_restore', 'set_time_limit',
        'set_include_path', 'restore_include_path',

        // Окружение
        'putenv', 'apache_setenv',

        // Обработчики ошибок
        'set_error_handler', 'set_exception_handler',
        'register_shutdown_function', 'register_tick_function',

        // Выполнение кода
        'eval', 'assert', 'create_function',

        // Headers и сессии
        'header', 'setcookie', 'setrawcookie',
        'session_start', 'session_destroy', 'session_regenerate_id',
        'session_write_close', 'session_commit',
    ];

    /**
     * Суперглобальные переменные
     */
    private const array SUPERGLOBALS = [
        '_GET', '_POST', '_REQUEST', '_COOKIE', '_SESSION',
        '_SERVER', '_ENV', '_FILES', 'GLOBALS',
    ];

    /**
     * Классифицирует узел AST и возвращает тип побочного эффекта
     *
     * @param  Node $node Узел AST для анализа
     * @return SideEffectType Тип побочного эффекта
     */
    public function classify(Node $node): SideEffectType
    {
        return match (true) {
            // Stmt\Expression - обёртка над выражением, анализируем внутреннее выражение
            $node instanceof Node\Stmt\Expression => $this->classify($node->expr),

            // Echo - всегда I/O
            $node instanceof Node\Stmt\Echo_ => SideEffectType::IO,

            // Print, exit, die
            $node instanceof Node\Expr\Print_ => SideEffectType::IO,
            $node instanceof Node\Expr\Exit_ => SideEffectType::IO,

            // Eval - изменение глобального состояния
            $node instanceof Node\Expr\Eval_ => SideEffectType::GLOBAL_STATE,

            // Include/Require - может содержать любой код
            $node instanceof Node\Expr\Include_ => SideEffectType::MIXED,

            // Вызовы функций
            $node instanceof Node\Expr\FuncCall => $this->classifyFunctionCall($node),

            // Методы и статические вызовы
            $node instanceof Node\Expr\MethodCall => $this->classifyMethodCall($node),
            $node instanceof Node\Expr\StaticCall => $this->classifyStaticCall($node),

            // Переменные (проверяем суперглобалы)
            $node instanceof Node\Expr\Variable => $this->classifyVariable($node),
            $node instanceof Node\Expr\ArrayDimFetch => $this->classifyArrayAccess($node),

            // Присваивание глобальных переменных
            $node instanceof Node\Expr\Assign => $this->classifyAssignment($node),
            $node instanceof Node\Expr\AssignOp => $this->classifyAssignment($node),
            $node instanceof Node\Expr\AssignRef => $this->classifyAssignment($node),

            // Global statement
            $node instanceof Node\Stmt\Global_ => SideEffectType::GLOBAL_STATE,

            // Static variables
            $node instanceof Node\Stmt\Static_ => SideEffectType::GLOBAL_STATE,

            // Составные узлы - анализируем детей
            $node instanceof Node\Stmt\If_,
            $node instanceof Node\Stmt\While_,
            $node instanceof Node\Stmt\For_,
            $node instanceof Node\Stmt\Foreach_,
            $node instanceof Node\Stmt\TryCatch,
            $node instanceof Node\Expr\Ternary,
            $node instanceof Node\Expr\BinaryOp,
            $node instanceof Node\Expr\UnaryMinus,
            $node instanceof Node\Expr\UnaryPlus,
            $node instanceof Node\Expr\BitwiseNot,
            $node instanceof Node\Expr\BooleanNot,
            $node instanceof Node\Expr\PreInc,
            $node instanceof Node\Expr\PreDec,
            $node instanceof Node\Expr\PostInc,
            $node instanceof Node\Expr\PostDec
                => $this->classifyChildren($node),

            // По умолчанию считаем чистым
            default => SideEffectType::PURE,
        };
    }

    /**
     * Классифицирует вызов функции
     */
    private function classifyFunctionCall(Node\Expr\FuncCall $node): SideEffectType
    {
        $funcName = $this->getFunctionName($node);
        if (!$funcName) {
            // Динамический вызов функции - не можем точно определить
            return SideEffectType::MIXED;
        }

        $funcName = strtolower($funcName);

        // Проверяем по категориям функций
        if (in_array($funcName, self::IO_FUNCTIONS, true)) {
            return SideEffectType::IO;
        }

        if (in_array($funcName, self::DATABASE_FUNCTIONS, true)) {
            return SideEffectType::DATABASE;
        }

        if (in_array($funcName, self::HTTP_FUNCTIONS, true)) {
            return SideEffectType::HTTP;
        }

        if (in_array($funcName, self::NON_DETERMINISTIC_FUNCTIONS, true)) {
            return SideEffectType::NON_DETERMINISTIC;
        }

        if (in_array($funcName, self::GLOBAL_STATE_FUNCTIONS, true)) {
            return SideEffectType::GLOBAL_STATE;
        }

        // Проверяем, является ли функция чистой (из Sandbox)
        if ($this->isPureFunction($funcName)) {
            return SideEffectType::PURE;
        }

        // Пользовательские функции - нужен дополнительный анализ
        // По умолчанию считаем потенциально опасными
        return SideEffectType::MIXED;
    }

    /**
     * Классифицирует вызов метода
     */
    private function classifyMethodCall(Node\Expr\MethodCall $node): SideEffectType
    {
        // Проверяем известные паттерны
        $methodName = $this->getMethodName($node);
        if (!$methodName) {
            return SideEffectType::MIXED;
        }

        $methodName = strtolower($methodName);

        // PDO методы
        if (in_array($methodName, ['query', 'exec', 'prepare', 'execute'], true)) {
            return SideEffectType::DATABASE;
        }

        // HTTP клиенты
        if (in_array($methodName, ['get', 'post', 'put', 'delete', 'request', 'send'], true)) {
            return SideEffectType::HTTP;
        }

        // Для остальных методов нужен анализ класса
        return SideEffectType::MIXED;
    }

    /**
     * Классифицирует статический вызов
     */
    private function classifyStaticCall(Node\Expr\StaticCall $node): SideEffectType
    {
        // Аналогично методам
        return $this->classifyMethodCall(
            new Node\Expr\MethodCall(
                new Node\Expr\Variable('tmp'),
                $node->name,
                $node->args
            )
        );
    }

    /**
     * Классифицирует переменную
     */
    private function classifyVariable(Node\Expr\Variable $node): SideEffectType
    {
        if (is_string($node->name)) {
            $varName = $node->name;
            if (in_array($varName, self::SUPERGLOBALS, true)) {
                return SideEffectType::EXTERNAL_STATE;
            }
        }

        return SideEffectType::PURE;
    }

    /**
     * Классифицирует доступ к элементу массива
     */
    private function classifyArrayAccess(Node\Expr\ArrayDimFetch $node): SideEffectType
    {
        // Проверяем, не является ли это доступом к суперглобалу
        $varEffect = $this->classify($node->var);
        if ($varEffect === SideEffectType::EXTERNAL_STATE) {
            return SideEffectType::EXTERNAL_STATE;
        }

        // Проверяем индекс
        if ($node->dim instanceof \PhpParser\Node\Expr) {
            $dimEffect = $this->classify($node->dim);
            return $varEffect->combine($dimEffect);
        }

        return $varEffect;
    }

    /**
     * Классифицирует присваивание
     */
    private function classifyAssignment(Node\Expr $node): SideEffectType
    {
        if (
            !($node instanceof Node\Expr\Assign
            || $node instanceof Node\Expr\AssignOp
            || $node instanceof Node\Expr\AssignRef)
        ) {
            return SideEffectType::PURE;
        }

        // Проверяем левую часть присваивания
        $leftEffect = $this->classify($node->var);

        // Если присваиваем в глобал или суперглобал
        if (
            $leftEffect === SideEffectType::EXTERNAL_STATE
            || $leftEffect === SideEffectType::GLOBAL_STATE
        ) {
            return $leftEffect;
        }

        // Проверяем правую часть
        $rightEffect = $this->classify($node->expr);

        // Комбинируем эффекты
        return $leftEffect->combine($rightEffect);
    }

    /**
     * Классифицирует составной узел через анализ детей
     */
    private function classifyChildren(Node $node): SideEffectType
    {
        $combinedEffect = SideEffectType::PURE;

        foreach ($node->getSubNodeNames() as $name) {
            $subNode = $node->$name;

            if ($subNode instanceof Node) {
                $effect = $this->classify($subNode);
                $combinedEffect = $combinedEffect->combine($effect);
            } elseif (is_array($subNode)) {
                foreach ($subNode as $item) {
                    if ($item instanceof Node) {
                        $effect = $this->classify($item);
                        $combinedEffect = $combinedEffect->combine($effect);
                    }
                }
            }

            // Оптимизация: если уже MIXED, дальше можно не проверять
            if ($combinedEffect === SideEffectType::MIXED) {
                break;
            }
        }

        return $combinedEffect;
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
     * Получает имя метода
     */
    private function getMethodName(Node\Expr\MethodCall $node): ?string
    {
        if ($node->name instanceof Node\Identifier) {
            return $node->name->toString();
        }

        return null;
    }

    /**
     * Проверяет, является ли функция чистой
     *
     * Использует тот же whitelist, что и Sandbox
     */
    public function isPureFunction(string $funcName): bool
    {
        // Используем список из Sandbox::ALLOWED_PURE_FUNCTIONS
        // Для простоты дублируем основные категории
        $pureFunctions = [
            // Математические
            'abs', 'acos', 'asin', 'atan', 'ceil', 'cos', 'exp', 'floor',
            'log', 'max', 'min', 'pow', 'round', 'sin', 'sqrt', 'tan',

            // Строковые
            'strlen', 'strtolower', 'strtoupper', 'substr', 'trim',
            'ltrim', 'rtrim', 'str_replace', 'strpos', 'explode', 'implode',
            'ucfirst', 'lcfirst', 'ucwords', 'md5', 'sha1',

            // Массивы
            'array_merge', 'array_keys', 'array_values', 'array_map',
            'array_filter', 'array_slice', 'count', 'in_array',
            'array_search', 'sort', 'rsort', 'ksort',

            // Типы
            'is_array', 'is_string', 'is_int', 'is_bool', 'is_null',
            'is_numeric', 'intval', 'floatval', 'strval', 'boolval',
        ];

        return in_array($funcName, $pureFunctions, true);
    }

    /**
     * Анализирует блок кода (массив узлов) и возвращает тип эффекта
     *
     * @param  Node[] $nodes Массив узлов для анализа
     * @return SideEffectType Комбинированный тип эффекта
     */
    public function classifyBlock(array $nodes): SideEffectType
    {
        $combinedEffect = SideEffectType::PURE;

        foreach ($nodes as $node) {
            if ($node instanceof Node) {
                $effect = $this->classify($node);
                $combinedEffect = $combinedEffect->combine($effect);

                if ($combinedEffect === SideEffectType::MIXED) {
                    break;
                }
            }
        }

        return $combinedEffect;
    }
}
