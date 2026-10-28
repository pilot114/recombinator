<?php

declare(strict_types=1);

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NodeConnectingVisitor;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as Printer;
use Recombinator\Transformation\Visitor\RuntimeAutoloadVisitor;

function applyRuntimeAutoload(string $code, array $fileMap): string
{
    $ast = new ParserFactory()->createForNewestSupportedVersion()->parse('<?php ' . $code) ?? [];
    foreach (new PhpParser\NodeFinder()->findInstanceOf($ast, Node\Expr\Include_::class) as $include) {
        $include->setAttribute('runtimeAutoload', true);
        $include->setAttribute('includeUnresolvable', true);
    }

    $ast = new NodeTraverser(new NodeConnectingVisitor())->traverse($ast);
    $ast = new NodeTraverser(new RuntimeAutoloadVisitor($fileMap))->traverse($ast);

    return new Printer()->prettyPrint($ast);
}

it('removes the Composer autoloader when no outside class can be loaded', function (): void {
    $fileMap = ['vendor/lib/Used.php' => '<?php namespace Lib; class Used {}', 'vendor/lib/Other.php' => '<?php namespace Lib; class Other {}'];

    expect(applyRuntimeAutoload('class Lib_Used {} require_once __DIR__ . "/vendor/autoload.php"; new Lib_Used();', $fileMap))
        ->not->toContain('autoload.php')
        ->and(applyRuntimeAutoload('require_once __DIR__ . "/vendor/autoload.php"; new \Lib\Other();', $fileMap))
        ->toContain('autoload.php');
});
