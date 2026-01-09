<?php

namespace Recombinator\Interactive;

use PhpParser\Node;

/**
 * Представляет одно изменение в коде
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

    public function getBeforeState(): array
    {
        return $this->beforeState;
    }

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
