<?php

namespace Recombinator\Interactive;

use PhpParser\Node;

/**
 * Представление одного изменения кода в интерактивной сессии
 *
 * Хранит информацию о типе изменения, затронутом узле AST
 * и состоянии до/после для возможности отката (undo/redo).
 */
class Change
{
    public const TYPE_RENAME = 'rename';

    public const TYPE_EXTRACT = 'extract';

    public const TYPE_INTRODUCE_VAR = 'introduce_var';

    public const TYPE_SIMPLIFY = 'simplify';

    public const TYPE_CUSTOM = 'custom';

    public function __construct(
        private readonly string $type,
        private readonly string $description,
        private readonly Node $node,
        private readonly array $beforeState,
        private readonly array $afterState,
        private int $timestamp = 0
    ) {
        $this->timestamp = $timestamp ?: time();
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getNode(): Node
    {
        return $this->node;
    }

    /**
     * @return array<mixed>
     */
    public function getBeforeState(): array
    {
        return $this->beforeState;
    }

    /**
     * @return array<mixed>
     */
    public function getAfterState(): array
    {
        return $this->afterState;
    }

    public function getTimestamp(): int
    {
        return $this->timestamp;
    }

    /**
     * Форматированное представление
     */
    public function format(): string
    {
        $time = date('H:i:s', $this->timestamp);
        return sprintf(
            "[%s] %s: %s",
            $time,
            strtoupper($this->type),
            $this->description
        );
    }
}
