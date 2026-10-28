<?php

declare(strict_types=1);

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as Printer;
use Recombinator\Transformation\Visitor\RemoveUnusedClassVisitor;

function applyRemoveUnusedClass(string $code): string
{
    $ast = new ParserFactory()->createForNewestSupportedVersion()->parse($code) ?? [];
    $ast = new NodeTraverser(new PhpParser\NodeVisitor\ParentConnectingVisitor())->traverse($ast);
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

it('does not count array keys and is_a() class names as uses', function (): void {
    $ast = new ParserFactory()->createForNewestSupportedVersion()->parse(
        '<?php class Oracle {} class Pg {} class Lite {}
        $strategy = ["Oracle" => 2][$platform::class] ?? 1;
        $isPg = is_a($platform, "Pg");
        $driver = new (["sqlite" => "Lite"]["sqlite"])();',
    ) ?? [];
    $ast    = new NodeTraverser(new PhpParser\NodeVisitor\ParentConnectingVisitor())->traverse($ast);
    $ast    = new NodeTraverser(new RemoveUnusedClassVisitor())->traverse($ast);

    $result = new Printer()->prettyPrint($ast);

    expect($result)->not->toContain('class Oracle')
        ->not->toContain('class Pg')
        ->toContain('class Lite');
});

it('treats functions extracted from static methods as graph nodes, not roots', function (): void {
    $result = applyRemoveUnusedClass(
        '<?php class Files { public static $start; public function get() { return Files_isSupported(); } }
        function Files_isSupported() { return Files::$start ??= 1; }
        class Used { public static function make() { return Used_make(); } }
        function Used_make() { return new Used(); }
        function helper() { return 1; }
        echo Used_make(), helper();',
    );

    expect($result)->not->toContain('class Files')->not->toContain('function Files_isSupported')
        ->toContain('class Used')->toContain('function Used_make')->toContain('function helper');
});

it('keeps classes named by a finite set of runtime-built strings', function (): void {
    $result = applyRemoveUnusedClass(
        '<?php class Faker_Provider_Address {} class Faker_Provider_Person {} class Faker_Provider_Lorem {}
        function make($p) { return new (sprintf("Faker\\\\Provider\\\\%s", $p))(); }
        make("Address"); make("Person");',
    );

    expect($result)->toContain('class Faker_Provider_Address')->toContain('class Faker_Provider_Person')
        ->not->toContain('class Faker_Provider_Lorem');
});
