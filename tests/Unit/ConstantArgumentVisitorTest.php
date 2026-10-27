<?php

declare(strict_types=1);

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NodeConnectingVisitor;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as Printer;
use Recombinator\Transformation\Visitor\ConstantArgumentVisitor;

function applyConstantArgument(string $code): string
{
    $ast = new ParserFactory()->createForNewestSupportedVersion()->parse('<?php ' . $code) ?? [];
    $ast = new NodeTraverser(new NodeConnectingVisitor())->traverse($ast);
    $ast = new NodeTraverser(new ConstantArgumentVisitor())->traverse($ast);

    return new Printer()->prettyPrint($ast);
}

it('specializes a function for an argument that is the same constant everywhere', function (): void {
    $result = applyConstantArgument(
        'function connect(array $params, $config = null) {
            $d = make($params["driver"] ?? null, $params["driverClass"] ?? null, isset($params["host"]));
            return new Conn($params, $d, $config);
        }
        function make(?string $driver, ?string $class) { return $class === null ? $driver : $class; }
        $a = connect(["driver" => "pdo_sqlite", "path" => ROOT . "/db"]);
        $b = make("x", null);',
    );

    expect($result)
        ->toContain('$d = make("pdo_sqlite" ?? null, null ?? null, false);')
        ->toContain('return new Conn($params, $d, null);')
        // make вызывается с разными аргументами — не специализируется
        ->toContain('return $class === null ? $driver : $class;');
});

it('keeps parameters that differ, change or are used as callables', function (string $code): void {
    expect(applyConstantArgument($code))->toContain('return $v;');
})->with([
    'different'   => ['function f($v) { return $v; } f(1); f(2);'],
    'reassigned'  => ['function f($v) { $v = 2; return $v; } f(1);'],
    'callable'    => ['function f($v) { return $v; } f(1); array_map("f", [3]);'],
    'type coerce' => ['function f(string $v) { return $v; } f(1);'],
    'by ref'      => ['function f($v) { g($v); return $v; } f(1);'],
]);

it('substitutes a reassigned parameter only where it is read before the first write', function (): void {
    $result = applyConstantArgument(
        'function make(?string $driver, ?string $class) {
            if ($class === null) { $class = ["a" => "A"][$driver]; }
            return new $class();
        }
        make("a", null);',
    );

    expect($result)->toContain('if (null === null) {')
        ->toContain('$class = ["a" => "A"]["a"];')
        ->toContain('return new $class();');
});
