<?php

declare(strict_types=1);

namespace Recombinator\Interactive;

/**
 * История изменений для интерактивного редактирования
 *
 * Позволяет отслеживать и откатывать примененные изменения
 */
class ChangeHistory
{
    /** @var array<Change> */
    private array $changes = [];

    private int $currentPosition = -1;

    /**
     * Добавляет изменение в историю
     */
    public function addChange(Change $change): void
    {
        // Удаляем все изменения после текущей позиции (при откате назад и новом изменении)
        if ($this->currentPosition < count($this->changes) - 1) {
            $this->changes = array_slice($this->changes, 0, $this->currentPosition + 1);
        }

        $this->changes[] = $change;
        $this->currentPosition = count($this->changes) - 1;
    }

    /**
     * Откатывает последнее изменение
     */
    public function undo(): ?Change
    {
        if (!$this->canUndo()) {
            return null;
        }

        $change = $this->changes[$this->currentPosition];
        $this->currentPosition--;

        return $change;
    }

    /**
     * Повторяет отмененное изменение
     */
    public function redo(): ?Change
    {
        if (!$this->canRedo()) {
            return null;
        }

        $this->currentPosition++;
        return $this->changes[$this->currentPosition];
    }

    /**
     * Проверяет, можно ли откатить изменение
     */
    public function canUndo(): bool
    {
        return $this->currentPosition >= 0;
    }

    /**
     * Проверяет, можно ли повторить изменение
     */
    public function canRedo(): bool
    {
        return $this->currentPosition < count($this->changes) - 1;
    }

    /**
     * Возвращает текущее изменение
     */
    public function getCurrentChange(): ?Change
    {
        if ($this->currentPosition < 0 || $this->currentPosition >= count($this->changes)) {
            return null;
        }

        return $this->changes[$this->currentPosition];
    }

    /**
     * Возвращает все изменения
     *
     * @return array<Change>
     */
    public function getAllChanges(): array
    {
        return $this->changes;
    }

    /**
     * Возвращает количество изменений
     */
    public function getCount(): int
    {
        return count($this->changes);
    }

    /**
     * Очищает историю
     */
    public function clear(): void
    {
        $this->changes = [];
        $this->currentPosition = -1;
    }

    /**
     * Возвращает краткую статистику
     */
    public function getSummary(): string
    {
        $total = count($this->changes);
        $current = $this->currentPosition + 1;

        return sprintf(
            "Changes: %d/%d (Undo: %s, Redo: %s)",
            $current,
            $total,
            $this->canUndo() ? 'yes' : 'no',
            $this->canRedo() ? 'yes' : 'no'
        );
    }
}
