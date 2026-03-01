<?php

declare(strict_types=1);

use Recombinator\Support\ParseException;

it(
    'throws ParseException on invalid PHP syntax',
    function (): void {
        $tmpDir = sys_get_temp_dir() . '/recombinator_parse_test_' . uniqid();
        mkdir($tmpDir);
        $cacheDir = $tmpDir . '/cache';
        mkdir($cacheDir);

        $entryFile = $tmpDir . '/invalid.php';
        file_put_contents($entryFile, '<?php function { invalid syntax !!!');

        $parser = new \Recombinator\Core\Parser($entryFile, $cacheDir);

        expect(fn() => $parser->run())
            ->toThrow(ParseException::class);

        // Cleanup
        array_map(unlink(...), glob($cacheDir . '/*') ?: []);
        rmdir($cacheDir);
        unlink($entryFile);
        rmdir($tmpDir);
    }
);

it(
    'provides file name in ParseException message',
    function (): void {
        $tmpDir = sys_get_temp_dir() . '/recombinator_parse_test_' . uniqid();
        mkdir($tmpDir);
        $cacheDir = $tmpDir . '/cache';
        mkdir($cacheDir);

        $entryFile = $tmpDir . '/broken.php';
        file_put_contents($entryFile, '<?php function { invalid');

        $parser = new \Recombinator\Core\Parser($entryFile, $cacheDir);

        try {
            $parser->run();
            expect(true)->toBeFalse(); // should not reach here
        } catch (ParseException $parseException) {
            expect($parseException->getMessage())->toContain('index.php');
        } finally {
            array_map(unlink(...), glob($cacheDir . '/*') ?: []);
            rmdir($cacheDir);
            if (file_exists($entryFile)) {
                unlink($entryFile);
            }

            rmdir($tmpDir);
        }
    }
);
