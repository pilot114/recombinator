<?php

class Point
{
    public function __construct(
        public readonly float $x,
        public readonly float $y,
    ) {}

    public function distanceTo(Point $other): float
    {
        $dx = $this->x - $other->x;
        $dy = $this->y - $other->y;
        return sqrt($dx * $dx + $dy * $dy);
    }

    public function label(): string
    {
        return '(' . $this->x . ', ' . $this->y . ')';
    }
}
