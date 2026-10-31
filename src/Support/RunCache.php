<?php

declare(strict_types=1);

namespace Recombinator\Support;

/**
 * Указатели на последний завершённый прогон входа в кэше прогонов (public/api.php).
 *
 * Прогон с другой версией конвейера продолжает с результата прошлого прогона того же
 * входа и того же окружения — указатель `<вход>[.<окружение>].latest` хранит его ключ.
 * Прогон другого окружения не подходит: результат зависел бы от истории кэша (прогон без
 * окружения трёхдневной давности дал Slim Demo 40 тыс. строк вместо 22 тыс.).
 */
final readonly class RunCache
{
    public function __construct(private string $dir)
    {
    }

    /**
     * Последний завершённый прогон того же входа и окружения; null — начинать с исходного кода.
     *
     * @return array<string, mixed>|null
     */
    public function previous(string $inputKey, string $envHash, string $code): ?array
    {
        $pointer = $this->pointer($inputKey, $envHash);
        $key     = is_file($pointer) ? trim((string) file_get_contents($pointer)) : '';
        $file    = $this->dir . '/' . $key . '.run';
        if ($key === '' || !is_file($file)) {
            return null;
        }

        $raw = @gzuncompress((string) file_get_contents($file));
        $old = $raw !== false ? @unserialize($raw) : null;

        // main_ast — AST до фазы читаемости: из итога после неё продолжать нельзя
        if (
            !is_array($old) || !($old['done'] ?? false) || !is_array($old['main_ast'] ?? null)
            || ($old['input_key'] ?? null) !== $inputKey || ($old['original'] ?? null) !== $code
        ) {
            return null;
        }

        $old['resumed_from'] = $key;

        return $old;
    }

    /**
     * Запоминает завершённый прогон как последний и удаляет файлы прогона, на который
     * указатель вёл раньше: его ключ (версия конвейера) больше не встретится.
     */
    public function markLatest(string $inputKey, string $envHash, string $runKey): void
    {
        $pointer  = $this->pointer($inputKey, $envHash);
        $previous = is_file($pointer) ? trim((string) file_get_contents($pointer)) : '';
        file_put_contents($pointer, $runKey);

        // shortcut: брошенные незавершёнными прогоны (правка src посреди прогона) остаются, их убирает «Сбросить кэш»
        if ($previous !== '' && $previous !== $runKey) {
            foreach (['.run', '.done', '.lock'] as $extension) {
                @unlink($this->dir . '/' . $previous . $extension);
            }
        }
    }

    private function pointer(string $inputKey, string $envHash): string
    {
        return $this->dir . '/' . $inputKey . ($envHash !== '' ? '.' . $envHash : '') . '.latest';
    }
}
