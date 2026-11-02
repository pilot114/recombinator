<?php

declare(strict_types=1);

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NodeConnectingVisitor;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as Printer;
use Recombinator\Core\Config;
use Recombinator\Transformation\Visitor\SingleImplementationInterfaceVisitor;

/** @param array<string, string> $files */
function applySingleImplementation(string $code, array $files = [], ?Config $config = null): string
{
    $ast = new ParserFactory()->createForNewestSupportedVersion()->parse('<?php ' . $code) ?? [];
    $ast = new NodeTraverser(new NodeConnectingVisitor())->traverse($ast);
    $ast = new NodeTraverser(new SingleImplementationInterfaceVisitor($files, $config))->traverse($ast);

    return new Printer()->prettyPrint($ast);
}

it('replaces an interface with its only implementation hierarchy', function (): void {
    $result = applySingleImplementation(
        'interface Base {} interface I extends Base {} class S implements I {} class T extends S {}
        function f(?I $s): I|int { try { return $s instanceof I ? $s : 1; } catch (I $e) { return 0; } }
        $ok = $x instanceof Base;',
    );

    expect($result)->toContain('function f(?\S $s): \S|int')
        ->and($result)->toContain('$s instanceof \S ? $s : 1')
        ->and($result)->toContain('catch (\S $e)')
        ->and($result)->toContain('$ok = $x instanceof \S;')
        ->and($result)->toContain('class S implements I');
});

it('turns a check against an interface without implementations into false', function (): void {
    expect(applySingleImplementation('interface I {} $a = $x instanceof I; $b = f() instanceof I;'))
        ->toContain('$a = false;')
        ->toContain('$b = f() instanceof I;');
});

it('keeps interfaces that several hierarchies or outside code implement', function (string $code, array $files): void {
    expect(applySingleImplementation($code, $files))->toContain('$x instanceof I');
})->with([
    'two classes'    => ['interface I {} class A implements I {} class B implements I {} $x instanceof I;', []],
    'enum'           => ['interface I {} class A implements I {} enum E implements I {} $x instanceof I;', []],
    'anonymous'      => ['interface I {} class A implements I {} $o = new class implements I {}; $x instanceof I;', []],
    'external file'  => ['interface I {} class A implements I {} $x instanceof I; $c = "V\\\\X";', ['vendor/X.php' => '<?php namespace V; class X implements \I {}']],
]);

it('keeps interfaces of the public contract in types', function (): void {
    $result = applySingleImplementation(
        'interface Catalogue { public function add(Catalogue $c): void; } class Messages implements Catalogue { public function add(Catalogue $c): void {} }',
        [],
        new Config()->setPublicContract(['Catalogue']),
    );

    // Код вне программы проверяет тип по интерфейсу; частичная замена ломала бы совместимость методов
    expect($result)->toContain("public function add(Catalogue \$c): void\n    {")->not->toContain('add(Messages $c)');
});
