<?php

declare(strict_types=1);

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NodeConnectingVisitor;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as Printer;
use Recombinator\Transformation\Visitor\GlobalConstantVisitor;

function applyGlobalConstant(string $code): string
{
    $ast = new ParserFactory()->createForNewestSupportedVersion()->parse('<?php ' . $code) ?? [];
    $ast = new NodeTraverser(new NodeConnectingVisitor())->traverse($ast);
    $ast = new NodeTraverser(new GlobalConstantVisitor())->traverse($ast);

    return new Printer()->prettyPrint($ast);
}

it('substitutes top-level constants after their declaration', function (): void {
    $result = applyGlobalConstant(
        'function early() { return ROOT; }
        $before = ROOT;
        const ROOT = "/srv/app";
        define("DEBUG", true);
        $path = ROOT . "/var"; $f = function () { return [ROOT, \DEBUG]; };',
    );

    expect($result)->toContain('return ROOT;')
        ->toContain('$before = ROOT;')
        ->toContain('$path = "/srv/app" . "/var";')
        ->toContain('return ["/srv/app", true];')
        ->toContain('const ROOT = "/srv/app";');
});

it('keeps constants declared conditionally or more than once', function (): void {
    $result = applyGlobalConstant('if ($x) { define("A", 1); } define("B", 1); define("B", 2); $v = [A, B];');

    expect($result)->toContain('$v = [A, B];');
});
