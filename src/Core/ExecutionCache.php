<?php

declare(strict_types=1);

namespace Recombinator\Core;

/**
 * Кеш для результатов предвыполнения кода
 *
 * Используется для хранения результатов выполнения "чистых" функций,
 * чтобы избежать повторного выполнения одинакового кода.
 *
 * Кеш хранится в памяти (runtime cache) и не сохраняется между запусками.
 * Для долговременного кеширования можно расширить класс и добавить
 * сохранение в файловую систему или другое хранилище.
 */
class ExecutionCache implements \Recombinator\Contract\CacheInterface
{
    /**
     * Хранилище кеша
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

    /**
     * Порядок доступа к элементам (для LRU)
     *
     * @var array<int, string>
     */
    private array $accessOrder = [];

    public function __construct(
        /**
         * Максимальный размер кеша (количество элементов)
         * При превышении начинается вытеснение старых элементов (LRU)
         */
        private readonly int $maxSize = 1000
    ) {
    }

    /**
     * Проверяет наличие значения в кеше
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->cache);
    }

    /**
     * Получает значение из кеша
     *
     * @return mixed|null Значение или null если ключ не найден
     */
    public function get(string $key): mixed
    {
        if (!$this->has($key)) {
            $this->stats['misses']++;
            return null;
        }

        $this->stats['hits']++;
        $this->updateAccessOrder($key);
        return $this->cache[$key];
    }

    /**
     * Сохраняет значение в кеше
     */
    public function set(string $key, mixed $value): void
    {
        // Проверяем, нужно ли освободить место
        if (!$this->has($key) && count($this->cache) >= $this->maxSize) {
            $this->evictLeastRecentlyUsed();
        }

        $this->cache[$key] = $value;
        $this->updateAccessOrder($key);
        $this->stats['sets']++;
    }

    /**
     * Удаляет значение из кеша
     */
    public function delete(string $key): void
    {
        unset($this->cache[$key]);
        $this->removeFromAccessOrder($key);
    }

    /**
     * Удаляет значение из кеша (CacheInterface)
     */
    public function remove(string $key): void
    {
        $this->delete($key);
    }

    /**
     * Очищает весь кеш
     */
    public function clear(): void
    {
        $this->cache = [];
        $this->accessOrder = [];
        $this->stats = [
            'hits' => 0,
            'misses' => 0,
            'sets' => 0,
        ];
    }

    /**
     * Возвращает статистику кеша
     *
     * @return array{hits: int, misses: int, sets: int, size: int, max_size: int, hit_rate: float}
     */
    public function getStats(): array
    {
        $total = $this->stats['hits'] + $this->stats['misses'];
        $hitRate = $total > 0 ? ($this->stats['hits'] / $total) * 100 : 0;

        return [
            'hits' => $this->stats['hits'],
            'misses' => $this->stats['misses'],
            'sets' => $this->stats['sets'],
            'size' => count($this->cache),
            'max_size' => $this->maxSize,
            'hit_rate' => round($hitRate, 2),
        ];
    }

    /**
     * Возвращает размер кеша
     */
    public function size(): int
    {
        return count($this->cache);
    }

    /**
     * Обновляет порядок доступа к элементу (для LRU)
     */
    private function updateAccessOrder(string $key): void
    {
        $this->removeFromAccessOrder($key);
        $this->accessOrder[] = $key;
    }

    /**
     * Удаляет элемент из порядка доступа
     */
    private function removeFromAccessOrder(string $key): void
    {
        $index = array_search($key, $this->accessOrder, true);
        if ($index !== false) {
            array_splice($this->accessOrder, $index, 1);
        }
    }

    /**
     * Вытесняет наименее используемый элемент (LRU - Least Recently Used)
     */
    private function evictLeastRecentlyUsed(): void
    {
        if ($this->accessOrder === []) {
            return;
        }

        $lruKey = array_shift($this->accessOrder);
        if ($lruKey !== null) {
            unset($this->cache[$lruKey]);
        }
    }

    /**
     * Экспортирует кеш для сохранения
     *
     * @return array{cache: array<string, mixed>, accessOrder: array<int, string>, stats: array{hits: int, misses: int, sets: int}}
     */
    public function export(): array
    {
        return [
            'cache' => $this->cache,
            'accessOrder' => $this->accessOrder,
            'stats' => $this->stats,
        ];
    }

    /**
     * Импортирует кеш из ранее сохраненных данных
     *
     * @param array{cache?: array<string, mixed>, accessOrder?: array<int, string>, stats?: array{hits: int, misses: int, sets: int}} $data
     */
    public function import(array $data): void
    {
        $this->cache = $data['cache'] ?? [];
        $this->accessOrder = $data['accessOrder'] ?? [];
        $this->stats = $data['stats'] ?? [
            'hits' => 0,
            'misses' => 0,
            'sets' => 0,
        ];
    }

    /**
     * Возвращает все ключи в кеше
     *
     * @return array<int, string>
     */
    public function keys(): array
    {
        return array_keys($this->cache);
    }

    /**
     * Возвращает все значения в кеше
     *
     * @return array<int, mixed>
     */
    public function values(): array
    {
        return array_values($this->cache);
    }

    /**
     * Проверяет, пуст ли кеш
     */
    public function isEmpty(): bool
    {
        return $this->cache === [];
    }
}
