<?php

declare(strict_types=1);

namespace SyntaxZoo;

use function array_filter;
use function array_map;
use function count;
use const PHP_INT_MAX;

final class Shelter
{
    /** @var list<Animal> */
    private array $animals = [];

    /** @var array<string, Status> */
    private array $statuses = [];

    public function __construct(
        public readonly string $city,
        public readonly int $capacity = PHP_INT_MAX,
    ) {
    }

    public function admit(Animal ...$incoming): int
    {
        $admitted = 0;
        foreach ($incoming as $animal) {
            if (count($this->animals) >= $this->capacity) {
                break;
            }
            $this->animals[] = $animal;
            $this->statuses[$animal->name] = Status::default();
            ++$admitted;
        }
        return $admitted;
    }

    public function updateStatus(string $name, Status $status): bool
    {
        if (!isset($this->statuses[$name])) {
            return false;
        }
        $this->statuses[$name] = $status;
        return true;
    }

    public function statusOf(string $name): ?Status
    {
        return $this->statuses[$name] ?? null;
    }

    /** @return \Generator<int, Animal> */
    public function bySpecies(string $species): \Generator
    {
        foreach ($this->animals as $i => $animal) {
            if ($animal->species() === $species) {
                yield $i => $animal;
            }
        }
    }

    /** @return \Generator<int, Animal> */
    public function all(): \Generator
    {
        yield from $this->animals;
    }

    /** @return list<string> */
    public function listAvailable(): array
    {
        $available = array_filter(
            $this->animals,
            fn(Animal $a): bool => ($this->statuses[$a->name] ?? Status::default()) === Status::Available,
        );
        return array_map(static fn(Animal $a): string => $a->describe(), $available);
    }
}
