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
    /**
     * Код ошибки «превышено время выполнения» (как у утилиты timeout)
     */
    public const int CODE_TIMEOUT = 124;

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

    public function isTimeout(): bool
    {
        return $this->code === self::CODE_TIMEOUT;
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
