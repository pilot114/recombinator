<?php

declare(strict_types=1);

use Recombinator\Core\ExecutionCache;

beforeEach(
    function (): void {
        $this->cache = new ExecutionCache(10); // Малый размер для тестирования LRU
    }
);

it(
    'can set and get value from cache',
    function (): void {
        $this->cache->set('key1', 'value1');

        expect($this->cache->get('key1'))->toBe('value1');
    }
);

it(
    'returns null for non-existent key',
    function (): void {
        expect($this->cache->get('nonExistent'))->toBeNull();
    }
);

it(
    'can check if key exists',
    function (): void {
        $this->cache->set('key1', 'value1');

        expect($this->cache->has('key1'))->toBeTrue()
        ->and($this->cache->has('nonExistent'))->toBeFalse();
    }
);

it(
    'can delete value from cache',
    function (): void {
        $this->cache->set('key1', 'value1');
        $this->cache->delete('key1');

        expect($this->cache->has('key1'))->toBeFalse();
    }
);

it(
    'can clear all cache',
    function (): void {
        $this->cache->set('key1', 'value1');
        $this->cache->set('key2', 'value2');
        $this->cache->clear();

        expect($this->cache->isEmpty())->toBeTrue();
    }
);

it(
    'tracks cache size',
    function (): void {
        $this->cache->set('key1', 'value1');
        $this->cache->set('key2', 'value2');

        expect($this->cache->size())->toBe(2);
    }
);

it(
    'implements LRU eviction when max size reached',
    function (): void {
        // Заполняем кеш до предела
        for ($i = 0; $i < 10; $i++) {
            $this->cache->set('key' . $i, 'value' . $i);
        }

        // Добавляем еще один элемент - должен вытеснить первый (key0)
        $this->cache->set('key10', 'value10');

        expect($this->cache->has('key0'))->toBeFalse()
        ->and($this->cache->has('key10'))->toBeTrue()
        ->and($this->cache->size())->toBe(10);
    }
);

it(
    'updates access order on get',
    function (): void {
        // Заполняем кеш
        for ($i = 0; $i < 10; $i++) {
            $this->cache->set('key' . $i, 'value' . $i);
        }

        // Обращаемся к key0, чтобы сделать его "свежим"
        $this->cache->get('key0');

        // Добавляем новый элемент - должен вытеснить key1, а не key0
        $this->cache->set('key10', 'value10');

        expect($this->cache->has('key0'))->toBeTrue()
        ->and($this->cache->has('key1'))->toBeFalse();
    }
);

it(
    'returns correct stats',
    function (): void {
        $this->cache->set('key1', 'value1');
        $this->cache->get('key1'); // hit
        $this->cache->get('key2'); // miss

        $stats = $this->cache->getStats();

        expect($stats['hits'])->toBe(1)
            ->and($stats['misses'])->toBe(1)
            ->and($stats['sets'])->toBe(1)
            ->and($stats['size'])->toBe(1)
            ->and($stats['hit_rate'])->toBe(50.0);
    }
);

it(
    'can export and import cache',
    function (): void {
        $this->cache->set('key1', 'value1');
        $this->cache->set('key2', 'value2');
        $this->cache->get('key1'); // для статистики

        $exported = $this->cache->export();

        $newCache = new ExecutionCache(10);
        $newCache->import($exported);

        expect($newCache->get('key1'))->toBe('value1')
        ->and($newCache->get('key2'))->toBe('value2')
        ->and($newCache->size())->toBe(2);
    }
);

it(
    'can get all keys',
    function (): void {
        $this->cache->set('key1', 'value1');
        $this->cache->set('key2', 'value2');

        $keys = $this->cache->keys();

        expect($keys)->toContain('key1')
            ->and($keys)->toContain('key2')
            ->and(count($keys))->toBe(2);
    }
);

it(
    'can get all values',
    function (): void {
        $this->cache->set('key1', 'value1');
        $this->cache->set('key2', 'value2');

        $values = $this->cache->values();

        expect($values)->toContain('value1')
            ->and($values)->toContain('value2')
            ->and(count($values))->toBe(2);
    }
);

it(
    'can store complex values',
    function (): void {
        $complexValue = [
        'array' => [1, 2, 3],
        'string' => 'test',
        'nested' => ['a' => 'b'],
        ];

        $this->cache->set('complex', $complexValue);

        expect($this->cache->get('complex'))->toBe($complexValue);
    }
);

it(
    'overwrites existing key without increasing size',
    function (): void {
        $this->cache->set('key1', 'value1');
        expect($this->cache->size())->toBe(1);

        $this->cache->set('key1', 'value2');
        expect($this->cache->size())->toBe(1)
        ->and($this->cache->get('key1'))->toBe('value2');
    }
);
