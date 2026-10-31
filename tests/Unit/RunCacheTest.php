<?php

declare(strict_types=1);

use Recombinator\Support\RunCache;

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir() . '/runcache_' . uniqid();
    mkdir($this->dir);
    $this->save = function (string $key, array $run): void {
        file_put_contents($this->dir . '/' . $key . '.run', gzcompress(serialize($run), 1));
    };
});

afterEach(function (): void {
    array_map(unlink(...), glob($this->dir . '/*') ?: []);
    rmdir($this->dir);
});

$done = ['done' => true, 'main_ast' => [], 'input_key' => 'in', 'original' => '<?php echo 1;'];

it('resumes the latest finished run of the same input and environment', function () use ($done): void {
    ($this->save)('r1', $done);
    $cache = new RunCache($this->dir);
    $cache->markLatest('in', 'env', 'r1');

    expect($cache->previous('in', 'env', '<?php echo 1;'))->toMatchArray(['resumed_from' => 'r1'])
        ->and($cache->previous('in', 'other', '<?php echo 1;'))->toBeNull()
        ->and($cache->previous('in', '', '<?php echo 1;'))->toBeNull()
        ->and($cache->previous('in', 'env', '<?php echo 2;'))->toBeNull();
});

it('does not resume runs saved after the readability phase only', function () use ($done): void {
    ($this->save)('r1', ['main_ast' => null] + $done);
    $cache = new RunCache($this->dir);
    $cache->markLatest('in', '', 'r1');

    expect($cache->previous('in', '', '<?php echo 1;'))->toBeNull();
});

it('removes files of the run the pointer replaced', function () use ($done): void {
    ($this->save)('r1', $done);
    ($this->save)('r2', $done);
    touch($this->dir . '/r1.lock');
    touch($this->dir . '/r1.done');
    $cache = new RunCache($this->dir);
    $cache->markLatest('in', 'env', 'r1');
    $cache->markLatest('in', 'env', 'r2');

    expect(glob($this->dir . '/r1.*'))->toBe([])
        ->and(is_file($this->dir . '/r2.run'))->toBeTrue()
        ->and($cache->previous('in', 'env', '<?php echo 1;'))->toMatchArray(['resumed_from' => 'r2']);
});
