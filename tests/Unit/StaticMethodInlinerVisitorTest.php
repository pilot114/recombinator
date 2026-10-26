<?php

declare(strict_types=1);

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as Printer;
use Recombinator\Transformation\Visitor\StaticMethodInlinerVisitor;

function applyStaticInliner(string $code): string
{
    $ast = new ParserFactory()->createForNewestSupportedVersion()->parse($code) ?? [];
    $ast = new NodeTraverser(new StaticMethodInlinerVisitor())->traverse($ast);

    return new Printer()->prettyPrint($ast);
}

it('inlines single-return static method with arguments', function (): void {
    $result = applyStaticInliner('<?php class A { static function f($x) { return $x + 1; } } echo A::f(2);');
    expect($result)->toContain('echo 2 + 1;');
});

it('does not substitute argument into itself when it contains a same-named variable', function (): void {
    $result = applyStaticInliner('<?php class A { static function f($x) { return $x->foo(); } } echo A::f($x->bar());');
    expect($result)->toContain('echo $x->bar()->foo();');
});

it('substitutes swapped arguments without re-substitution', function (): void {
    $result = applyStaticInliner('<?php class A { static function f($a, $b) { return $a - $b; } } echo A::f($b, $a);');
    expect($result)->toContain('echo $b - $a;');
});

it('skips self-recursive static methods', function (): void {
    $result = applyStaticInliner('<?php class A { static function f($n) { return $n ? self::f($n - 1) : 0; } } echo A::f(3);');
    expect($result)->toContain('echo A::f(3);');
});

it('does not inline a static method using private members outside its class', function (): void {
    $code = '<?php class T { private static $reg; private const M = [1];
        public static function reg() { return self::$reg ??= self::M; }
        public static function pub() { return 1; }
        public function own() { return self::reg(); } }
        class U { function f() { return [T::reg(), T::pub()]; } }';
    $ast    = new PhpParser\ParserFactory()->createForNewestSupportedVersion()->parse($code) ?? [];
    $ast    = new PhpParser\NodeTraverser(new Recombinator\Transformation\Visitor\StaticMethodInlinerVisitor())->traverse($ast);

    $result = new PhpParser\PrettyPrinter\Standard()->prettyPrint($ast);

    expect($result)->toContain('return [T::reg(), 1];');
});
