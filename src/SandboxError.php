<?php

declare(strict_types=1);

namespace Recombinator;

use Throwable;

/**
 * Класс для представления ошибок, возникших при выполнении в sandbox
 */
class SandboxError
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
