<?php

declare(strict_types=1);

namespace Recombinator\Contract;

/**
 * Интерфейс для управления кешем
 *
 * Определяет контракт для работы с кешем результатов выполнения.
 * Используется для кеширования результатов чистых вычислений
 * и оптимизации повторных вычислений одних и тех же выражений.
 */
interface CacheInterface
{
    /**
     * Проверяет наличие ключа в кеше
     */
    public function has(string $key): bool;

    /**
     * Получает значение из кеша
     */
    public function get(string $key): mixed;

    /**
     * Сохраняет значение в кеш
     */
    public function set(string $key, mixed $value): void;

    /**
     * Удаляет ключ из кеша
     */
    public function remove(string $key): void;

    /**
     * Очищает весь кеш
     */
    public function clear(): void;
}
