<?php

declare(strict_types=1);

namespace Recombinator\Support;

/**
 * Исходники проекта-примера для конвейера: src/, vendor/ и файлы корня (bootstrap.php,
 * settings.php и т.п. подключаются через require).
 */
final class ProjectFiles
{
    /**
     * @return array<string, string> путь от корня проекта → код
     *
     * @throws \UnexpectedValueException каталог не читается
     */
    public static function collect(string $projectDir): array
    {
        $fileMap = [];
        foreach (['src', 'vendor'] as $subdir) {
            $scanDir = $projectDir . '/' . $subdir;
            if (!is_dir($scanDir)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($scanDir, \RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                    $rel = ltrim(str_replace($projectDir, '', $file->getPathname()), '/');
                    $fileMap[$rel] = (string) file_get_contents($file->getPathname());
                }
            }
        }

        foreach (glob($projectDir . '/*.php') ?: [] as $rootFile) {
            $fileMap[basename($rootFile)] = (string) file_get_contents($rootFile);
        }

        return $fileMap;
    }
}
