<?php

declare(strict_types=1);

use PhpParser\Node\Expr\Variable;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use Recombinator\Interactive\VariableRenamer;

/** Переименовывает первую переменную $from в коде, возвращает код. */
function renameFirst(string $code, string $from, string $to): string
{
    $ast    = new ParserFactory()->createForNewestSupportedVersion()->parse($code) ?? [];
    $target = new NodeFinder()->findFirst($ast, static fn($n): bool => $n instanceof Variable && $n->name === $from);
    assert($target instanceof Variable);
    VariableRenamer::rename($ast, $target, $to);

    return new Standard()->prettyPrint($ast);
}

it('renames inside arrow functions and closures that use the variable, not in other functions', function (): void {
    $result = renameFirst(
        '<?php function f() { $a = 1; $g = fn() => $a; $h = function () use (&$a) { $a++; }; return $a; } function k($a) { return $a; }',
        'a',
        'count',
    );

    expect($result)->toContain('$count = 1;')->toContain('fn() => $count')->toContain('use (&$count)')->toContain('$count++;')
        ->toContain('return $count;')->toContain('function k($a)');
});

it('refuses renames that could change behaviour', function (string $code, string $message): void {
    expect(fn(): string => renameFirst($code, 'a', 'b'))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'collision in arrow function' => ['<?php $a = 1; $f = fn($b) => $a + $b;', 'already used'],
    'compact'                     => ['<?php function f() { $a = 1; return compact("a"); }', 'by name'],
    'global'                      => ['<?php $a = 1; function f() { global $a; return $a; }', 'global'],
    'variable variables'          => ['<?php $a = 1; $n = "a"; echo $$n;', 'variable variables'],
]);
