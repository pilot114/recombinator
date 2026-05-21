<?php

declare(strict_types=1);

namespace SyntaxZoo;

enum Status: string implements Identifiable
{
    case Available = 'available';
    case Adopted = 'adopted';
    case Quarantine = 'quarantine';

    public function label(): string
    {
        return match ($this) {
            self::Available => 'Готов к усыновлению',
            self::Adopted => 'Усыновлён',
            self::Quarantine => 'На карантине',
        };
    }

    public function isTerminal(): bool
    {
        return match (true) {
            $this === self::Adopted => true,
            default => false,
        };
    }

    public static function default(): self
    {
        return self::Available;
    }

    public function id(): string
    {
        return 'STATUS:' . $this->value;
    }
}
