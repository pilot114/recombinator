<?php

declare(strict_types=1);

namespace SyntaxZoo;

class Cat extends Animal
{
    public ?Counter $stepCounter = null;

    public function species(): string
    {
        return 'cat';
    }

    public function speak(): string
    {
        return "{$this->name} purrs softly...\n";
    }

    public function __toString(): string
    {
        return $this->describe();
    }

    public function __invoke(string $action): string
    {
        return "$this->name performs $action";
    }

    public function getCount(): ?int
    {
        return $this->stepCounter?->value;
    }
}
