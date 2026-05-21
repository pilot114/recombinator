<?php

declare(strict_types=1);

namespace SyntaxZoo;

use function strtolower;
use const PHP_EOL;

final class Dog extends Animal
{
    public const string ORIGIN = 'canidae';

    public static int $totalDogs = 0;

    public function __construct(
        string $name,
        int $age,
        public readonly string $breed = 'mixed',
    ) {
        parent::__construct($name, $age);
        ++static::$totalDogs;
    }

    public function species(): string
    {
        return 'dog';
    }

    public function speak(): string
    {
        return strtolower($this->name) . ' says Woof! (' . static::ORIGIN . ')' . PHP_EOL;
    }

    public static function puppy(string $name): self
    {
        return new self(name: $name, age: 1);
    }

    public function rename(string $newName): self
    {
        $copy = clone $this;
        // readonly через clone with — PHP 8.3+
        return $copy->withName($newName);
    }

    private function withName(string $newName): self
    {
        return new self(name: $newName, age: $this->age, breed: $this->breed);
    }
}
