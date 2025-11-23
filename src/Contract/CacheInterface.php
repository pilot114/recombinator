<?php

declare(strict_types=1);

namespace Recombinator\Contract;

/**
 * Interface for cache management
 */
interface CacheInterface
{
    /**
     * Check if cache has key
     *
     * @param string $key
     * @return bool
     */
    public function has(string $key): bool;

    /**
     * Get value from cache
     *
     * @param string $key
     * @return mixed
     */
    public function get(string $key): mixed;

    /**
     * Set value to cache
     *
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function set(string $key, mixed $value): void;

    /**
     * Remove key from cache
     *
     * @param string $key
     * @return void
     */
    public function remove(string $key): void;

    /**
     * Clear all cache
     *
     * @return void
     */
    public function clear(): void;
}
