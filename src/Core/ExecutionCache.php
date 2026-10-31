<?php

declare(strict_types=1);

namespace Recombinator\Core;

/**
 * Кеш для результатов предвыполнения кода
 *
 * Используется для хранения результатов выполнения "чистых" функций,
 * чтобы избежать повторного выполнения одинакового кода.
 *
 * Хранится в памяти; public/api.php сохраняет его вместе с прогоном.
 * При переполнении вытесняется давно не использованная запись (LRU).
 */
class ExecutionCache
{
    /**
     * Хранилище: порядок ключей — порядок использования, первым идёт самый старый
     *
     * @var array<string, mixed>
     */
    private array $cache = [];

    /**
     * Статистика кеша
     *
     * @var array{hits: int, misses: int, sets: int}
     */
    private array $stats = [
        'hits' => 0,
        'misses' => 0,
        'sets' => 0,
    ];

    public function __construct(
        /**
         * Максимальный размер кеша (количество элементов)
         */
        private readonly int $maxSize = 1000
    ) {
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->cache);
    }

    /**
     * @return mixed|null Значение или null если ключ не найден
     */
    public function get(string $key): mixed
    {
        if (!$this->has($key)) {
            $this->stats['misses']++;
            return null;
        }

        $this->stats['hits']++;
        $value = $this->cache[$key];
        $this->touch($key, $value);

        return $value;
    }

    public function set(string $key, mixed $value): void
    {
        if (!$this->has($key) && count($this->cache) >= $this->maxSize) {
            unset($this->cache[array_key_first($this->cache)]);
        }

        $this->touch($key, $value);
        $this->stats['sets']++;
    }

    /**
     * Возвращает статистику кеша
     *
     * @return array{hits: int, misses: int, sets: int, size: int, max_size: int, hit_rate: float}
     */
    public function getStats(): array
    {
        $total = $this->stats['hits'] + $this->stats['misses'];

        return [
            ...$this->stats,
            'size' => count($this->cache),
            'max_size' => $this->maxSize,
            'hit_rate' => $total > 0 ? round($this->stats['hits'] / $total * 100, 2) : 0.0,
        ];
    }

    /** Перемещает ключ в конец: он становится самым свежим. */
    private function touch(string $key, mixed $value): void
    {
        unset($this->cache[$key]);
        $this->cache[$key] = $value;
    }
}
