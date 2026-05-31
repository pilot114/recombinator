<?php

declare(strict_types=1);

namespace Recombinator\Support;

/**
 * Обёртка для успешного результата sandbox-выполнения.
 * Позволяет отличить null-результат от ошибки/отсутствия результата.
 */
readonly class SandboxResult
{
    public function __construct(public mixed $value)
    {
    }
}
