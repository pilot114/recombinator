<?php

declare(strict_types=1);

// PSR-4-style автолоадер: пример рассчитан на запуск без composer.
spl_autoload_register(static function (string $cls): void {
    if (!str_starts_with($cls, 'SyntaxZoo\\')) {
        return;
    }
    $file = __DIR__ . '/src/' . substr($cls, 10) . '.php';
    if (file_exists($file)) {
        require $file;
    }
});

use SyntaxZoo\Animal;
use SyntaxZoo\Cat;
use SyntaxZoo\Counter;
use SyntaxZoo\Dog;
use SyntaxZoo\Logger;
use SyntaxZoo\Shelter;
use SyntaxZoo\ShelterException;
use SyntaxZoo\Status;

// ── Создание объектов: named args, статический фабричный метод ───────────────
$shelter = new Shelter(city: 'Berlin', capacity: 10);
$rex = new Dog(name: 'Rex', age: 4, breed: 'husky');
$milo = Dog::puppy('Milo');
$whiskers = new Cat('Whiskers', 7);
$whiskers->stepCounter = new Counter(value: 42);

// ── Spread + varargs ────────────────────────────────────────────────────────
$batch = [$rex, $milo];
$admitted = $shelter->admit(...$batch, ...[$whiskers]);
echo "Admitted: $admitted" . PHP_EOL;

// ── foreach + match (true) + методы enum ────────────────────────────────────
foreach ([$rex, $milo, $whiskers] as $animal) {
    $animal->track(steps: 3);
    $status = match (true) {
        $animal->species() === 'dog' && Animal::isOld($animal->getSteps()) => Status::Quarantine,
        $animal->species() === 'cat' => Status::Adopted,
        default => Status::Available,
    };
    $shelter->updateStatus($animal->name, $status);
    echo $animal->speak();
}

// ── Генератор + yield from ──────────────────────────────────────────────────
foreach ($shelter->bySpecies('dog') as $i => $dog) {
    echo "Dog #$i: $dog->name" . PHP_EOL;
}
$total = 0;
foreach ($shelter->all() as $_) {
    $total++;
}
echo "Total: $total" . PHP_EOL;

// ── for + do-while + readonly + immutable update ────────────────────────────
$counter = new Counter(value: 0);
for ($i = 1; $i <= 3; $i++) {
    $counter = $counter->inc(by: $i);
}
$j = 0;
do {
    $counter = $counter->inc();
    $j++;
} while ($j < 2);

// ── Spread в вызове varargs, list destructuring ─────────────────────────────
$combined = $counter->combine(new Counter(10), new Counter(5));
[$low, $high] = $combined->splitAt(7);
echo "Split: $low/$high" . PHP_EOL;

// ── while + break + continue ────────────────────────────────────────────────
$n = 5;
while ($n > 0) {
    --$n;
    if ($n === 3) {
        continue;
    }
    if ($n === 1) {
        break;
    }
    echo "tick $n" . PHP_EOL;
}

// ── switch + spaceship ──────────────────────────────────────────────────────
switch ($combined->value <=> 10) {
    case -1:
        echo "less" . PHP_EOL;
        break;
    case 0:
        echo "equal" . PHP_EOL;
        break;
    case 1:
        echo "greater" . PHP_EOL;
        break;
}

// ── instanceof, ternary, ?:, null coalesce, nullsafe ────────────────────────
print ($whiskers instanceof Animal ? 'is animal' : 'not') . PHP_EOL;
echo (Logger::nowdocExample() ?: 'fallback') . PHP_EOL;
echo ($whiskers->stepCounter?->value ?? 0) . PHP_EOL;
echo ($whiskers->getCount() ?? -1) . PHP_EOL;

// ── Магические методы: __toString, __invoke ─────────────────────────────────
echo (string) $whiskers . PHP_EOL;
echo $whiskers('jump') . PHP_EOL;

// ── try / catch / finally / never ───────────────────────────────────────────
$result = Logger::guarded(static function () use ($shelter): string {
    if (!$shelter->updateStatus('Ghost', Status::Adopted)) {
        Logger::panic('No such animal');
    }
    return 'ok';
});
echo "Result: " . ($result ?? 'null') . PHP_EOL;

// ── First-class callable syntax + closure binding через use ─────────────────
$describe = $rex->describe(...);
echo $describe() . PHP_EOL;

$prefix = '>>';
$tag = static fn(string $s): string => "$prefix $s";
echo $tag('tagged') . PHP_EOL;

// ── Spread в литерале массива, array_sum ────────────────────────────────────
$values = [1, 2, 3];
echo array_sum([...$values, 4, 5]) . PHP_EOL;

// ── empty / isset / unset / global ──────────────────────────────────────────
$bag = ['key' => 'value'];
if (isset($bag['key']) && !empty($bag['key'])) {
    unset($bag['key']);
}
echo (empty($bag) ? 'empty' : 'not') . PHP_EOL;

function readCity(): string
{
    global $shelter;
    return $shelter->city;
}
echo readCity() . PHP_EOL;

// ── clone + heredoc dump + enum::isTerminal ─────────────────────────────────
$rex2 = $rex->rename('Rex II');
echo $rex2->describe() . PHP_EOL;
echo ($shelter->statusOf('Whiskers')?->isTerminal() ?? false) ? 'terminal' : 'open';
echo PHP_EOL;
echo Logger::dump() . PHP_EOL;
echo Animal::registryLine();
