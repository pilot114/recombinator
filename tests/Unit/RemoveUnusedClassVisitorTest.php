<?php

declare(strict_types=1);

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as Printer;
use Recombinator\Transformation\Visitor\RemoveUnusedClassVisitor;

function applyRemoveUnusedClass(string $code): string
{
    $ast = new ParserFactory()->createForNewestSupportedVersion()->parse($code) ?? [];
    $ast = new NodeTraverser(new RemoveUnusedClassVisitor())->traverse($ast);

    return new Printer()->prettyPrint($ast);
}

it('removes classes reachable only from dead classes', function (): void {
    $result = applyRemoveUnusedClass(
        '<?php class Live { function f() { return new Dep(); } } class Dep {}
        class Dead { function f() { return new OnlyFromDead(); } } class OnlyFromDead {}
        $x = new Live();',
    );

    expect($result)
        ->toContain('class Live')->toContain('class Dep')
        ->not->toContain('class Dead')->not->toContain('class OnlyFromDead');
});

it('does not count type hints, instanceof and catch as uses', function (): void {
    $result = applyRemoveUnusedClass(
        '<?php interface I {} class T {} class E extends Exception {}
        function f(T $t): I { try {} catch (E $e) {} return $t instanceof I; }',
    );

    expect($result)->not->toContain('interface I')->not->toContain('class T')->not->toContain('class E');
});

it('keeps parents, interfaces, traits and attributes of live classes', function (): void {
    $result = applyRemoveUnusedClass(
        '<?php interface I {} trait Tr {} abstract class P {} #[Attribute] class Attr {}
        #[Attr] class C extends P implements I { use Tr; } $c = new C();',
    );

    expect($result)->toContain('interface I')->toContain('trait Tr')->toContain('class P')->toContain('class Attr');
});

it('keeps classes named by strings and ::class', function (): void {
    $result = applyRemoveUnusedClass(
        '<?php class ByString {} class ByConst {} class ByCallable { static function m() {} } class Unused {}
        $a = "ByString"; $b = ByConst::class; call_user_func("ByCallable::m");',
    );

    expect($result)
        ->toContain('class ByString')->toContain('class ByConst')->toContain('class ByCallable')
        ->not->toContain('class Unused');
});

it('keeps classes referenced from functions and enum cases', function (): void {
    $result = applyRemoveUnusedClass('<?php enum S { case A; } class U {} function f() { return [S::A, U::class]; }');

    expect($result)->toContain('enum S')->toContain('class U');
});
