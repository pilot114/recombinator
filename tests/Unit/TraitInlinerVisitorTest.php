<?php

declare(strict_types=1);

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as Printer;
use Recombinator\Transformation\Visitor\TraitInlinerVisitor;

function applyTraitInliner(string $code): string
{
    $ast = new ParserFactory()->createForNewestSupportedVersion()->parse($code) ?? [];
    $ast = new NodeTraverser(new TraitInlinerVisitor())->traverse($ast);

    return new Printer()->prettyPrint($ast);
}

it('expands nested traits with aliases', function (): void {
    $result = applyTraitInliner(
        '<?php trait Inner { public function get() { return 1; } public function doGet() { return 2; } }
        trait Outer { use Inner { doGet as private innerGet; } private function doGet() { return $this->innerGet(); } }
        class C { use Outer; public function own() {} }',
    );

    expect($result)
        ->not->toContain('trait ')
        ->toContain('public function get()')
        ->toContain('private function innerGet()')
        ->toContain('private function doGet()')
        ->toContain('public function own()')
        ->and(substr_count($result, 'function doGet('))->toBe(1);
});

it('applies insteadof and visibility changes', function (): void {
    $result = applyTraitInliner(
        '<?php trait A { public function hi() { return "a"; } } trait B { public function hi() { return "b"; } }
        class C { use A, B { A::hi insteadof B; B::hi as protected; } }',
    );

    expect($result)->toContain('return "a";')->not->toContain('return "b";');
});

it('keeps classes with external traits untouched', function (): void {
    $result = applyTraitInliner('<?php trait Local { function f() {} } class C { use Local, \Psr\Log\LoggerAwareTrait; }');

    expect($result)->toContain('use Local, \Psr\Log\LoggerAwareTrait;')->toContain('trait Local');
});

it('copies trait constants and static properties', function (): void {
    $result = applyTraitInliner('<?php trait T { const X = 1; public static int $n = 0; } class C { use T; }');

    expect($result)->toContain('const X = 1;')->toContain('public static int $n = 0;');
});

it('keeps conditionally declared traits', function (): void {
    $result = applyTraitInliner('<?php if (PHP_VERSION_ID > 1) { trait T { function f() {} } } class C { use T; }');

    expect($result)->toContain('trait T')->toContain('use T;');
});
