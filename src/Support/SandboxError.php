<?php

declare(strict_types=1);

namespace Recombinator\Support;

use Throwable;

/**
 * Представление ошибки, возникшей при выполнении кода в sandbox
 *
 * Используется для безопасной обработки исключений, возникающих при
 * выполнении кода в изолированной среде. Позволяет отличить ошибки
 * выполнения от успешных результатов без использования исключений.
 */
readonly class SandboxError implements \Stringable
{
    public function __construct(
        private string $message,
        private int $code = 0,
        private ?Throwable $previous = null
    ) {
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getCode(): int
    {
        return $this->code;
    }

    public function getPrevious(): ?Throwable
    {
        return $this->previous;
    }

    public function __toString(): string
    {
        return sprintf(
            "SandboxError: %s (code: %d)",
            $this->message,
            $this->code
        );
    }
}
