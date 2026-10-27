<?php

declare(strict_types=1);

namespace Recombinator\Support;

/**
 * Частичное выполнение загрузки метаданных Doctrine: в каталоге проекта отдельным
 * процессом выполняется код, возвращающий EntityManager (как его строит приложение),
 * загружаются метаданные всех сущностей и находятся инварианты ClassMetadata —
 * свойства, значение которых у всех сущностей равно значению по умолчанию
 * (`inheritanceType` = NONE, `associationMappings` = [] и т.п.).
 *
 * Результат: ['Doctrine_ORM_Mapping_ClassMetadata' => ['inheritanceType' => 1, ...]]
 * (имя класса — плоское, как в программе после встраивания). Кэшируется в
 * var/cache/probes по коду пробы и содержимому проекта.
 */
final class DoctrineMetadataProbe
{
    /**
     * @param  string $code PHP-код (без `<?php`), выполняемый в каталоге проекта и возвращающий EntityManager
     * @return array<string, array<string, mixed>>
     */
    public static function invariants(string $projectDir, string $code, string $cacheKey, string $cacheDir): array
    {
        $file = $cacheDir . '/' . sha1($cacheKey . "\0" . $code) . '.json';
        if (is_file($file)) {
            $cached = json_decode((string) file_get_contents($file), true);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $script = tempnam(sys_get_temp_dir(), 'probe');
        if ($script === false) {
            return [];
        }

        file_put_contents($script, self::script($projectDir, $code));
        $output = [];
        $status = 0;
        exec(escapeshellarg(PHP_BINARY) . ' -d memory_limit=1G ' . escapeshellarg($script) . ' 2>/dev/null', $output, $status);
        @unlink($script);

        $result = $status === 0 ? json_decode(implode("\n", $output), true) : null;
        if (!is_array($result)) {
            return [];
        }

        @mkdir($cacheDir, 0777, true);
        file_put_contents($file, json_encode($result));

        return $result;
    }

    private static function script(string $projectDir, string $code): string
    {
        $dir = var_export($projectDir, true);

        return <<<PHP
            <?php
            chdir({$dir});
            require {$dir} . '/vendor/autoload.php';
            \$em = (static function () { {$code} })();
            \$all = \$em->getMetadataFactory()->getAllMetadata();
            \$result = [];
            foreach (array_unique(array_map(get_class(...), \$all)) as \$class) {
                \$reflection = new ReflectionClass(\$class);
                \$invariants = [];
                foreach (\$reflection->getProperties() as \$property) {
                    if (\$property->isStatic() || !\$property->hasDefaultValue()) {
                        continue;
                    }

                    \$default = \$property->getDefaultValue();
                    if (!is_scalar(\$default) && \$default !== null && \$default !== []) {
                        continue;
                    }

                    foreach (\$all as \$metadata) {
                        if (!\$metadata instanceof \$class || !\$property->isInitialized(\$metadata) || \$property->getValue(\$metadata) !== \$default) {
                            continue 2;
                        }
                    }

                    \$invariants[\$property->getName()] = \$default;
                }

                \$result[str_replace('\\\\', '_', \$class)] = \$invariants;
            }

            echo json_encode(\$result);
            PHP;
    }
}
