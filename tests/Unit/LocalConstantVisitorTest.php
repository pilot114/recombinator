<?php

declare(strict_types=1);

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NodeConnectingVisitor;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as Printer;
use Recombinator\Transformation\Visitor\LocalConstantVisitor;

function applyLocalConstant(string $code): string
{
    $ast = new ParserFactory()->createForNewestSupportedVersion()->parse('<?php ' . $code) ?? [];
    for ($i = 0; $i < 3; $i++) {
        $ast = new NodeTraverser(new NodeConnectingVisitor())->traverse($ast);
        $ast = new NodeTraverser(new LocalConstantVisitor())->traverse($ast);
    }

    return new Printer()->prettyPrint($ast);
}

it('substitutes a literal local assigned once and drops the assignment', function (): void {
    $result = applyLocalConstant('function f($cls) { $before = $cls; $cls = "Driver"; $n = 2; return [new $cls(), $n * $n, $before]; }');

    expect($result)->toContain('$before = $cls;')
        ->toContain('return [new ("Driver")(), 2 * 2, $before];')
        ->not->toContain('$cls = ')
        ->not->toContain('$n = ');
});

it('keeps locals written more than once or possibly by reference', function (string $body): void {
    expect(applyLocalConstant("function f() { {$body} return \$v; }"))->toContain('return $v;');
})->with([
    'twice'     => ['$v = 1; if (g()) { $v = 2; }'],
    'by ref'    => ['$v = 1; g($v);'],
    'loop'      => ['$v = 1; foreach ([2] as $v) {}'],
    'reference' => ['$v = 1; $r = &$v; $r = 2;'],
    'compact'   => ['$v = 1; compact("v");'],
]);

it('keeps the assignment while a closure captures the variable', function (): void {
    expect(applyLocalConstant('function f() { $v = 1; return function () use ($v) { return $v; }; }'))
        ->toContain('$v = 1;')
        ->toContain('use ($v)');
});

it('substitutes elements of a literal array read by literal keys, also at top level', function (): void {
    $result = applyLocalConstant(
        '$s = ["slim" => ["debug" => true, "log" => false], "dir" => ROOT . "/var"];
        $app->addErrorMiddleware($s["slim"]["debug"], $s["slim"]["log"]); $d = $s["dir"];
        function f() { $o = ["a" => 1]; $o["b"] = 2; return $o["a"]; }',
    );

    expect($result)->toContain('$app->addErrorMiddleware(true, false);')
        ->toContain('$d = ROOT . "/var";')
        ->not->toContain('$s = ')
        ->toContain('return $o["a"];');
});

it('keeps top-level variables visible to functions through global', function (): void {
    expect(applyLocalConstant('$v = 1; function g() { global $v; $v = 2; } g(); echo $v;'))->toContain('echo $v;');
});
