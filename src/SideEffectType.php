<?php

declare(strict_types=1);

namespace Recombinator;

/**
 * Типы побочных эффектов в PHP коде
 *
 * Классификация побочных эффектов необходима для:
 * 1. Определения "чистых" блоков кода, которые можно развернуть
 * 2. Группировки кода по типу побочного эффекта при свёртке
 * 3. Анализа зависимостей между блоками кода
 * 4. Оптимизации порядка выполнения операций
 */
enum SideEffectType: string
{
    /**
     * Чистый код без побочных эффектов
     *
     * Характеристики:
     * - Детерминированный результат (одинаковый вход = одинаковый выход)
     * - Не изменяет внешнее состояние
     * - Не зависит от внешнего состояния
     * - Может быть безопасно вычислен в compile-time
     *
     * Примеры:
     * - Математические операции: 1 + 2, sqrt(16)
     * - Строковые операции: strtoupper('test'), substr('hello', 0, 2)
     * - Операции с массивами: array_merge([1, 2], [3, 4])
     * - Чистые пользовательские функции
     */
    case PURE = 'pure';

    /**
     * I/O операции (Input/Output)
     *
     * Характеристики:
     * - Чтение или запись данных во внешние системы
     * - Взаимодействие с файловой системой
     * - Вывод в stdout/stderr
     * - Может быть заблокировано или завершиться с ошибкой
     *
     * Примеры:
     * - echo, print, printf, var_dump
     * - file_get_contents, file_put_contents
     * - fopen, fwrite, fread, fclose
     * - readfile, file, unlink, mkdir
     */
    case IO = 'io';

    /**
     * Работа с внешним состоянием (суперглобалы)
     *
     * Характеристики:
     * - Чтение данных из внешнего окружения
     * - Зависит от контекста выполнения
     * - Недетерминированный результат
     * - Может быть разным при каждом запуске
     *
     * Примеры:
     * - $_GET, $_POST, $_REQUEST
     * - $_SESSION, $_COOKIE
     * - $_SERVER, $_ENV
     * - getenv(), putenv()
     */
    case EXTERNAL_STATE = 'external_state';

    /**
     * Изменение глобального состояния
     *
     * Характеристики:
     * - Изменение глобальных переменных
     * - Изменение статических свойств классов
     * - Изменение конфигурации PHP
     * - Влияет на выполнение остального кода
     *
     * Примеры:
     * - global $var; $var = 123;
     * - MyClass::$staticProp = 'value';
     * - ini_set('display_errors', '1')
     * - set_time_limit(60)
     */
    case GLOBAL_STATE = 'global_state';

    /**
     * Работа с базой данных
     *
     * Характеристики:
     * - Запросы к БД (SELECT, INSERT, UPDATE, DELETE)
     * - Транзакции
     * - Может быть медленным
     * - Может завершиться с ошибкой
     *
     * Примеры:
     * - mysqli_query, mysql_query
     * - PDO::query, PDO::exec
     * - pg_query, oci_execute
     * - $db->select(), $db->insert()
     */
    case DATABASE = 'database';

    /**
     * HTTP запросы и сетевые операции
     *
     * Характеристики:
     * - Запросы к внешним API
     * - Сетевые соединения
     * - Может быть очень медленным
     * - Может завершиться с ошибкой или таймаутом
     *
     * Примеры:
     * - curl_exec, curl_init
     * - file_get_contents('http://...')
     * - fsockopen, socket_connect
     * - mail(), stream_socket_client
     */
    case HTTP = 'http';

    /**
     * Недетерминированные операции
     *
     * Характеристики:
     * - Результат зависит от времени или случайности
     * - Невозможно предсказать результат
     * - Нельзя кешировать результат
     * - Нельзя вычислить в compile-time
     *
     * Примеры:
     * - rand, mt_rand, random_int
     * - time, microtime, date
     * - uniqid, bin2hex(random_bytes())
     */
    case NON_DETERMINISTIC = 'non_deterministic';

    /**
     * Смешанные побочные эффекты
     *
     * Используется когда код содержит несколько типов эффектов
     * или когда невозможно точно определить тип эффекта
     *
     * Примеры:
     * - Функция, которая и читает файл, и делает HTTP запрос
     * - Блок кода с разными типами эффектов
     */
    case MIXED = 'mixed';

    /**
     * Получить приоритет эффекта
     *
     * Приоритет используется для определения порядка применения
     * оптимизаций и группировки кода при свёртке.
     * Меньшее значение = выше приоритет.
     *
     * @return int Приоритет эффекта (0-7)
     */
    public function getPriority(): int
    {
        return match ($this) {
            self::PURE => 0,              // Самый высокий - можно оптимизировать первым
            self::NON_DETERMINISTIC => 1, // Нельзя кешировать
            self::EXTERNAL_STATE => 2,    // Зависит от окружения
            self::IO => 3,                // I/O операции
            self::GLOBAL_STATE => 4,      // Изменяет состояние
            self::DATABASE => 5,          // Может быть медленным
            self::HTTP => 6,              // Очень медленно
            self::MIXED => 7,             // Самый низкий - обрабатывается последним
        };
    }

    /**
     * Проверяет, является ли эффект чистым (безопасным для оптимизации)
     */
    public function isPure(): bool
    {
        return $this === self::PURE;
    }

    /**
     * Проверяет, можно ли вычислить эффект в compile-time
     */
    public function isCompileTimeEvaluable(): bool
    {
        return $this === self::PURE;
    }

    /**
     * Проверяет, можно ли кешировать результат
     */
    public function isCacheable(): bool
    {
        return match ($this) {
            self::PURE => true,
            self::EXTERNAL_STATE => true, // С осторожностью
            default => false,
        };
    }

    /**
     * Возвращает человекочитаемое описание типа эффекта
     */
    public function getDescription(): string
    {
        return match ($this) {
            self::PURE => 'Чистый код без побочных эффектов',
            self::IO => 'I/O операции (файлы, консоль)',
            self::EXTERNAL_STATE => 'Работа с внешним состоянием ($_GET, $_POST, $_SESSION)',
            self::GLOBAL_STATE => 'Изменение глобального состояния',
            self::DATABASE => 'Работа с базой данных',
            self::HTTP => 'HTTP запросы и сетевые операции',
            self::NON_DETERMINISTIC => 'Недетерминированные операции (rand, time)',
            self::MIXED => 'Смешанные побочные эффекты',
        };
    }

    /**
     * Комбинирует два типа эффектов
     *
     * Используется когда узел AST содержит несколько типов эффектов
     *
     * @param SideEffectType $other Другой тип эффекта
     * @return SideEffectType Результирующий тип эффекта
     */
    public function combine(SideEffectType $other): SideEffectType
    {
        // Если хотя бы один MIXED, результат MIXED
        if ($this === self::MIXED || $other === self::MIXED) {
            return self::MIXED;
        }

        // Если оба одинаковые, возвращаем тот же тип
        if ($this === $other) {
            return $this;
        }

        // Если один PURE, возвращаем другой
        if ($this === self::PURE) {
            return $other;
        }
        if ($other === self::PURE) {
            return $this;
        }

        // В остальных случаях - MIXED
        return self::MIXED;
    }
}
