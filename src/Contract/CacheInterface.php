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
     */
    public function has(string $key): bool;

    /**
     * Get value from cache
     */
    public function get(string $key): mixed;

    /**
     * Set value to cache
     */
    public function set(string $key, mixed $value): void;

    /**
     * Remove key from cache
     */
    public function remove(string $key): void;

    /**
     * Clear all cache
     */
    public function clear(): void;
}
