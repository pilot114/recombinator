<?php

declare(strict_types=1);

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NodeConnectingVisitor;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as Printer;
use Recombinator\Transformation\Visitor\CoalesceNullRemoveVisitor;
use Recombinator\Transformation\Visitor\ConstFoldVisitor;
use Recombinator\Transformation\Visitor\DeadBranchVisitor;
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

it('uses the value until the next write: also before a call that may take it by reference', function (): void {
    $result = applyLocalConstant('function f() { $v = 1; echo $v; if ($v > 0) { $v = 2; } g($v); }
        function h() { $o = ["a" => 1]; $x = $o["a"] + ($o["b"] ?? 3); return new W($o, $x); }');

    expect($result)->toContain('echo 1;')
        ->toContain('if (1 > 0) {')
        ->toContain('g($v);')
        ->toContain('$x = 1 + (null ?? 3);');
});

it('keeps locals passed to a call before the assignment', function (): void {
    // Вызов мог сохранить ссылку на переменную и изменить её позже
    expect(applyLocalConstant('function f() { g($v); $v = 1; h(); return $v; }'))->toContain('return $v;');
});

it('folds an inlined call with a literal array argument and cast parameters', function (): void {
    // DriverManager::getConnection(['driver' => 'pdo_sqlite']) после CallSiteInlinerVisitor
    $code = 'function f($config) {
        $params1 = ["driver" => "pdo_sqlite", "path" => "/x"];
        $driver11 = $params1["driver"] ?? null;
        if ($driver11 !== null) { $driver11 = (string) $driver11; }
        $driverClass11 = $params1["driverClass"] ?? null;
        if ($driverClass11 !== null) { $driverClass11 = (string) $driverClass11; }
        if ($driverClass11 === null) {
            if ($driver11 === null) { throw new DriverRequired("x"); }
            if (!isset(["pdo_mysql" => "My", "pdo_sqlite" => "Lite"][$driver11])) { throw new UnknownDriver(\sprintf("%s", $driver11)); }
            $driverClass11 = ["pdo_mysql" => "My", "pdo_sqlite" => "Lite"][$driver11];
        } elseif (!\is_a($driverClass11, "Driver", true)) {
            throw new InvalidDriverClass(\sprintf("%s", $driverClass11));
        }
        $driver1 = new $driverClass11();
        $wrapperClass1 = $params1["wrapperClass"] ?? "Connection";
        return new $wrapperClass1($params1, $driver1, $config);
    }';
    $ast = new ParserFactory()->createForNewestSupportedVersion()->parse('<?php ' . $code) ?? [];
    for ($i = 0; $i < 20; $i++) {
        foreach ([new LocalConstantVisitor(), new CoalesceNullRemoveVisitor(), new ConstFoldVisitor(), new DeadBranchVisitor()] as $visitor) {
            $ast = new NodeTraverser(new NodeConnectingVisitor())->traverse($ast);
            $ast = new NodeTraverser($visitor)->traverse($ast);
        }
    }

    expect(new Printer()->prettyPrint($ast))->toContain('$driver1 = new ("Lite")();')
        ->toContain('return new ("Connection")($params1, $driver1, $config);')
        ->not->toContain('"My"');
});
