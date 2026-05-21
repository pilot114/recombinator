<?php

declare(strict_types=1);

namespace SyntaxZoo;

use function sprintf;
use function strtoupper;
use const PHP_EOL;

interface Speakable
{
    public function speak(): string;
}

interface Identifiable
{
    public function id(): string;
}

trait Trackable
{
    private int $stepCount = 0;

    public function track(int $steps): void
    {
        $this->stepCount += $steps;
    }

    public function getSteps(): int
    {
        return $this->stepCount;
    }
}

abstract class Animal implements Speakable, Identifiable
{
    use Trackable;

    public const int MAX_AGE = 50;

    public const string ORIGIN = 'unknown';

    /** @var array<string, int> */
    protected static array $registry = [];

    public function __construct(
        public readonly string $name,
        protected int $age = 0,
    ) {
        static::$registry[static::class] = (static::$registry[static::class] ?? 0) + 1;
    }

    abstract public function species(): string;

    final public function describe(): string
    {
        return sprintf('%s the %s, age %d', $this->name, $this->species(), $this->age);
    }

    public function id(): string
    {
        return strtoupper($this->species()) . ':' . $this->name;
    }

    public static function isOld(int $age): bool
    {
        return $age >= self::MAX_AGE / 2;
    }

    public static function registryLine(): string
    {
        $line = '';
        foreach (static::$registry as $cls => $n) {
            $line .= "$cls=$n " . PHP_EOL;
        }
        return $line;
    }
}
