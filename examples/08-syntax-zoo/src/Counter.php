<?php

declare(strict_types=1);

namespace SyntaxZoo;

use function array_reduce;
use function max;
use function min;

readonly class Counter
{
    public function __construct(public int $value = 0)
    {
    }

    public function inc(int $by = 1): self
    {
        return new self($this->value + $by);
    }

    public function combine(self ...$others): self
    {
        $sum = array_reduce(
            $others,
            fn(int $acc, self $c): int => $acc + $c->value,
            $this->value,
        );
        return new self($sum);
    }

    /** @return array{int, int} */
    public function splitAt(int $point): array
    {
        return [min($this->value, $point), max(0, $this->value - $point)];
    }

    public function isPositive(): bool
    {
        return $this->value > 0;
    }
}
