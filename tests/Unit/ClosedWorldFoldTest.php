<?php

declare(strict_types=1);

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitor\NodeConnectingVisitor;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as Printer;
use Recombinator\Transformation\Visitor\ClassExistsFoldVisitor;
use Recombinator\Transformation\Visitor\StaticPropConstVisitor;

function applyClosedWorld(string $code, NodeVisitor $visitor): string
{
    $ast = new ParserFactory()->createForNewestSupportedVersion()->parse($code) ?? [];
    $ast = new NodeTraverser(new NodeConnectingVisitor())->traverse($ast);
    $ast = new NodeTraverser($visitor)->traverse($ast);

    return new Printer()->prettyPrint($ast);
}

it('replaces a never-written static property with its default', function (): void {
    $result = applyClosedWorld(
        '<?php class P { protected static array $f = ["A", "B"];
            public static function get(): array { return static::$f; }
            public static function set(array $f): void { static::$f = $f; } }
        $x = P::get();',
        new StaticPropConstVisitor(),
    );

    expect($result)->toContain('return ["A", "B"];');
});

it('keeps a static property written by a called method', function (): void {
    $result = applyClosedWorld(
        '<?php class P { protected static array $f = [];
            public static function get(): array { return self::$f; }
            public static function add(string $x): void { array_unshift(self::$f, $x); } }
        P::add("a"); $x = P::get();',
        new StaticPropConstVisitor(),
    );

    expect($result)->toContain('return self::$f;');
});

it('does not resolve static:: in a class with subclasses', function (): void {
    $result = applyClosedWorld(
        '<?php class P { protected static string $c = "A"; public static function c(): string { return static::$c; } }
        class Q extends P { protected static string $c = "B"; }',
        new StaticPropConstVisitor(),
    );

    expect($result)->toContain('return static::$c;');
});

it('turns a class-name property in new into a class name', function (): void {
    $result = applyClosedWorld(
        '<?php class F { protected static string $cls = "Impl";
            public static function make() { return new static::$cls(class_exists(static::$cls)); } }',
        new StaticPropConstVisitor(),
    );

    expect($result)->toContain('new \Impl(class_exists("Impl"))');
});

it('folds class_exists against the declared classes', function (): void {
    $result = applyClosedWorld(
        '<?php class Known {} interface I {}
        $a = class_exists("Known"); $b = class_exists("Missing\Pkg\Cls"); $c = interface_exists("Known");
        $d = class_exists("I"); $e = class_exists("ArrayObject"); $f = class_exists("NoNamespace");',
        new ClassExistsFoldVisitor(),
    );

    expect($result)
        ->toContain('$a = true;')->toContain('$b = false;')->toContain('$c = false;')
        ->toContain('$d = false;')->toContain('$e = true;')->toContain('$f = class_exists("NoNamespace");');
});

it('does not fold classes declared conditionally', function (): void {
    $result = applyClosedWorld('<?php if (PHP_VERSION_ID > 80000) { class Poly {} } $a = class_exists("Poly");', new ClassExistsFoldVisitor());

    expect($result)->toContain('class_exists("Poly")');
});

it('turns a string class in a static call into a name', function (): void {
    $result = applyClosedWorld('<?php class K { static function m() {} } "K"::m();', new ClassExistsFoldVisitor());

    expect($result)->toContain('K::m();');
});

it('declares classes with a missing external parent conditionally', function (): void {
    $ast = new ParserFactory()->createForNewestSupportedVersion()->parse(
        '<?php class Proxy extends \\Relay\\Relay {} class Sub extends Proxy {} class Plain extends \\RuntimeException {}',
    ) ?? [];
    $ast    = new NodeTraverser(new \Recombinator\Transformation\Visitor\ExternalParentGuardVisitor())->traverse($ast);
    $ast    = new NodeTraverser(new NodeConnectingVisitor())->traverse($ast);
    $ast    = new NodeTraverser(new ClassExistsFoldVisitor())->traverse($ast);

    $result = new Printer()->prettyPrint($ast);

    $guard = "if (class_exists('Relay\\Relay') || interface_exists('Relay\\Relay') || trait_exists('Relay\\Relay')) {\n";
    expect($result)
        ->toContain($guard . '    class Proxy')
        ->toContain($guard . '    class Sub')
        ->toContain("\nclass Plain extends \\RuntimeException");
});

it('aliases original names of inlined classes when Composer stays as a fallback', function (): void {
    $class = new PhpParser\Node\Stmt\Class_('Faker_Generator', [], ['originalName' => 'Faker\Generator']);
    $load  = new PhpParser\Node\Stmt\Expression(
        new PhpParser\Node\Expr\Include_(new PhpParser\Node\Scalar\String_('vendor/autoload.php'), PhpParser\Node\Expr\Include_::TYPE_REQUIRE_ONCE),
        ['runtimeAutoload' => true],
    );
    $ast    = new NodeTraverser(new \Recombinator\Transformation\Visitor\OriginalClassAliasVisitor())->traverse([$class, $load]);
    $result = new Printer()->prettyPrint($ast);

    expect($result)
        ->toContain("foreach (['Faker_Generator' => 'Faker\\Generator'] as \$flat => \$original)")
        ->toContain('class_alias($flat, $original, false);');
    expect(strpos($result, 'foreach'))->toBeLessThan(strpos($result, 'require_once'));
});

it('folds literal array lookups with PHP key rules', function (): void {
    $result = applyClosedWorld(
        '<?php $a = ["d" => ["dev" => true, "dir" => ROOT . "/x"]]["d"]["dev"]; $b = ["x", "y"][1]; $c = [1 => "a", "1" => "b"]["1"];
        $d = ["k" => f()]["j"]; $e = ["k" => 1, "o" => f()]["k"]; $g = ["k" => 1]["missing"];',
        new \Recombinator\Transformation\Visitor\ConstFoldVisitor(),
    );

    expect($result)
        ->toContain('$a = true;')->toContain('$b = "y";')->toContain('$c = "b";')
        ->toContain('$d = ["k" => f()]["j"];')->toContain('$e = ["k" => 1, "o" => f()]["k"];')
        ->toContain('$g = ["k" => 1]["missing"];');
});
