<?php

declare(strict_types=1);

namespace Recombinator\Interactive;

use PhpParser\Node;

/**
 * Сессия интерактивного редактирования
 *
 * Управляет:
 * - Текущим состоянием AST
 * - Историей изменений
 * - Предпочтениями пользователя
 * - Статистикой применённых изменений
 */
class EditSession
{
    private readonly ChangeHistory $history;

    /**
     *
     *
     * @var array<string, mixed>
     */
    private array $userPreferences = [];

    /**
     *
     *
     * @var array<string, int>
     */
    private array $appliedChangesStats = [];

    private readonly int $sessionStartTime;

    /**
     * @param Node[] $currentAst
     */
    public function __construct(private readonly array $currentAst, private readonly InteractiveEditResult $analysisResult)
    {
        $this->history = new ChangeHistory();
        $this->sessionStartTime = time();
        $this->loadDefaultPreferences();
    }

    /**
     * @return Node[]
     */
    public function getCurrentAst(): array
    {
        return $this->currentAst;
    }

    public function getAnalysisResult(): InteractiveEditResult
    {
        return $this->analysisResult;
    }

    public function getHistory(): ChangeHistory
    {
        return $this->history;
    }

    /**
     * Применяет изменение к AST
     */
    public function applyChange(Change $change): bool
    {
        // Здесь должна быть логика применения изменения к AST
        // Для простоты просто добавляем в историю

        $this->history->addChange($change);

        // Обновляем статистику
        $type = $change->getType();
        if (!isset($this->appliedChangesStats[$type])) {
            $this->appliedChangesStats[$type] = 0;
        }

        $this->appliedChangesStats[$type]++;

        return true;
    }

    /**
     * Откатывает последнее изменение
     */
    public function undo(): bool
    {
        $change = $this->history->undo();
        if (!$change instanceof \Recombinator\Interactive\Change) {
            return false;
        }

        // Здесь должна быть логика отката изменения в AST
        // Используем beforeState из Change

        // Обновляем статистику
        $type = $change->getType();
        if (isset($this->appliedChangesStats[$type]) && $this->appliedChangesStats[$type] > 0) {
            $this->appliedChangesStats[$type]--;
        }

        return true;
    }

    /**
     * Повторяет отмененное изменение
     */
    public function redo(): bool
    {
        $change = $this->history->redo();
        if (!$change instanceof \Recombinator\Interactive\Change) {
            return false;
        }

        // Здесь должна быть логика повтора изменения в AST

        // Обновляем статистику
        $type = $change->getType();
        if (!isset($this->appliedChangesStats[$type])) {
            $this->appliedChangesStats[$type] = 0;
        }

        $this->appliedChangesStats[$type]++;

        return true;
    }

    /**
     * Сохраняет предпочтение пользователя
     */
    public function setPreference(string $key, mixed $value): void
    {
        $this->userPreferences[$key] = $value;
    }

    /**
     * Получает предпочтение пользователя
     */
    public function getPreference(string $key, mixed $default = null): mixed
    {
        return $this->userPreferences[$key] ?? $default;
    }

    /**
     * Возвращает все предпочтения
     *
     * @return array<mixed>
     */
    public function getAllPreferences(): array
    {
        return $this->userPreferences;
    }

    /**
     * Сохраняет предпочтения в файл
     */
    public function savePreferences(string $path): bool
    {
        $data = json_encode($this->userPreferences, JSON_PRETTY_PRINT);
        return file_put_contents($path, $data) !== false;
    }

    /**
     * Загружает предпочтения из файла
     */
    public function loadPreferences(string $path): bool
    {
        if (!file_exists($path)) {
            return false;
        }

        $data = file_get_contents($path);
        if ($data === false) {
            return false;
        }

        $preferences = json_decode($data, true);
        if (!is_array($preferences)) {
            return false;
        }

        /**
 * @var array<string, mixed> $preferences
*/
        $this->userPreferences = array_merge($this->userPreferences, $preferences);
        return true;
    }

    /**
     * Возвращает статистику применённых изменений
     *
     * @return array<mixed>
     */
    public function getAppliedChangesStats(): array
    {
        return $this->appliedChangesStats;
    }

    /**
     * Возвращает общее количество применённых изменений
     */
    public function getTotalAppliedChanges(): int
    {
        return array_sum($this->appliedChangesStats);
    }

    /**
     * Возвращает длительность сессии в секундах
     */
    public function getSessionDuration(): int
    {
        return time() - $this->sessionStartTime;
    }

    /**
     * Возвращает краткую статистику сессии
     */
    public function getSessionSummary(): string
    {
        $duration = $this->getSessionDuration();
        $minutes = floor($duration / 60);
        $seconds = $duration % 60;

        $summary = sprintf(
            "Session Duration: %02d:%02d\n",
            $minutes,
            $seconds
        );

        $summary .= sprintf(
            "Applied Changes: %d\n",
            $this->getTotalAppliedChanges()
        );

        if ($this->appliedChangesStats !== []) {
            $summary .= "Changes by type:\n";
            foreach ($this->appliedChangesStats as $type => $count) {
                $summary .= sprintf("  %s: %d\n", $type, $count);
            }
        }

        return $summary . ("\n" . $this->history->getSummary());
    }

    private function loadDefaultPreferences(): void
    {
        $this->userPreferences = [
            'auto_apply_safe_changes' => false,
            'show_suggestions' => true,
            'max_suggestions_per_issue' => 3,
            'prioritize_critical' => true,
            'color_output' => true,
            'verbose' => false,
        ];
    }
}
