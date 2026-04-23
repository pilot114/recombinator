<?php

declare(strict_types=1);

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NodeConnectingVisitor;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;
use Recombinator\Transformation\Visitor\PureFunctionCacheVisitor;

function applyCache(string $code): string
{
    $parser  = new ParserFactory()->createForNewestSupportedVersion();
    $printer = new StandardPrinter();
    $ast     = $parser->parse($code) ?? [];

    $t1 = new NodeTraverser();
    $t1->addVisitor(new NodeConnectingVisitor());
    $ast = $t1->traverse($ast);

    $t2 = new NodeTraverser();
    $t2->addVisitor(new PureFunctionCacheVisitor());
    $ast = $t2->traverse($ast);

    return $printer->prettyPrint($ast);
}

// ── Встроенные чистые функции (abs, strlen, etc.) ─────────────────────────────

it('replaces repeated built-in pure call with assigned variable', function (): void {
    $code = '<?php
function foo() {
    $s = abs($x);
    $t = abs($x) + 1;
    return $t;
}';
    $result = applyCache($code);

    expect($result)->toContain('$s = abs($x)');
    expect($result)->toContain('$t = $s + 1');
    expect(substr_count($result, 'abs($x)'))->toBe(1);
});

it('replaces all subsequent repeated calls', function (): void {
    $code = '<?php
function foo() {
    $s = strlen($str);
    $a = strlen($str) + 1;
    $b = strlen($str) * 2;
}';
    $result = applyCache($code);

    expect(substr_count($result, 'strlen($str)'))->toBe(1);
    expect($result)->toContain('$a = $s + 1');
    expect($result)->toContain('$b = $s * 2');
});

it('does not replace calls with different arguments', function (): void {
    $code = '<?php
function foo() {
    $a = abs($x);
    $b = abs($y);
    return [$a, $b];
}';
    $result = applyCache($code);

    expect(substr_count($result, 'abs($x)'))->toBe(1);
    expect(substr_count($result, 'abs($y)'))->toBe(1);
});

// ── Пользовательские функции (определены в том же файле) ──────────────────────

it('replaces repeated local function call (defined in same file)', function (): void {
    // Параметры функции p1/p2 отличаются от аргументов вызова $a/$b — исключает
    // ложный подсчёт function-definition при substr_count.
    $code = '<?php
function hsum($p1, $p2) { return $p1 ^ $p2; }

function foo() {
    $s = hsum($a, $b);
    $t = hsum($a, $b) + 1;
    return $t;
}';
    $result = applyCache($code);

    expect($result)->toContain('$s = hsum($a, $b)');
    expect($result)->toContain('$t = $s + 1');
    expect(substr_count($result, 'hsum($a, $b)'))->toBe(1);
});

it('does not apply to calls in separate function scopes', function (): void {
    $code = '<?php
function foo() {
    $x = abs($n);
    return $x;
}
function bar() {
    $y = abs($n);
    return $y;
}';
    $result = applyCache($code);

    // abs($n) appears once in each function — no replacement needed
    expect(substr_count($result, 'abs($n)'))->toBe(2);
});

// ── Сценарий Б: первый вызов нигде не присвоен ───────────────────────────────

it('introduces cse variable when first call is inside expression', function (): void {
    $code = '<?php
function foo() {
    if (abs($x) > 0) {
        echo abs($x);
    }
}';
    $result = applyCache($code);

    expect(substr_count($result, 'abs($x)'))->toBe(1);
    expect($result)->toMatch('/\$__cse\d+\s*=\s*abs\(\$x\)/');
});

it('does not apply to non-local unknown functions', function (): void {
    $code = '<?php
function foo() {
    $a = unknown_external($x);
    $b = unknown_external($x) + 1;
}';
    $result = applyCache($code);

    // unknown_external not defined locally → not considered pure → no replacement
    expect(substr_count($result, 'unknown_external($x)'))->toBe(2);
});
