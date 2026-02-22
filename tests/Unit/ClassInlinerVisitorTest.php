<?php

declare(strict_types=1);

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NodeConnectingVisitor;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as Printer;
use Recombinator\Transformation\Visitor\ClassInlinerVisitor;
use Recombinator\Transformation\Visitor\TernarReturnVisitor;

function parseAndInline(string $code, bool $withTernar = false): string
{
    $parser   = new ParserFactory()->createForNewestSupportedVersion();
    $printer  = new Printer();
    $ast      = $parser->parse($code) ?? [];

    if ($withTernar) {
        $t = new NodeTraverser();
        $t->addVisitor(new NodeConnectingVisitor());
        $t->addVisitor(new TernarReturnVisitor());
        $ast = $t->traverse($ast);
    }

    $t = new NodeTraverser();
    $t->addVisitor(new NodeConnectingVisitor());
    $t->addVisitor(new ClassInlinerVisitor());

    $ast = $t->traverse($ast);

    return $printer->prettyPrint($ast);
}

it('removes the class definition', function (): void {
    $code = '<?php
class Foo {
    public $bar = 1;
}
$x = new Foo();
';
    $result = parseAndInline($code);
    expect($result)->not->toContain('class Foo');
});

it('replaces new X() with property variable assignments', function (): void {
    $code = '<?php
class Point {
    public $x = 0;
    public $y = 0;
}
$p = new Point();
';
    $result = parseAndInline($code);
    expect($result)
        ->toContain('$p__x = 0')
        ->toContain('$p__y = 0')
        ->not->toContain('new Point');
});

it('replaces method call with inlined single-return body', function (): void {
    $code = '<?php
class Calc {
    public $base = 10;
    public function add($x) {
        return $this->base + $x;
    }
}
$c = new Calc();
$r = $c->add(5);
';
    $result = parseAndInline($code);
    expect($result)
        ->toContain('$c__base = 10')
        ->toContain('$c__base + 5')
        ->not->toContain('$c->add')
        ->not->toContain('class Calc');
});

it('replaces property fetch with generated variable', function (): void {
    $code = '<?php
class Box {
    public $size = 42;
}
$b = new Box();
echo $b->size;
';
    $result = parseAndInline($code);
    expect($result)
        ->toContain('$b__size = 42')
        ->toContain('echo $b__size')
        ->not->toContain('$b->size');
});

it('inlines constructor body and maps args to $this->prop', function (): void {
    $code = '<?php
class Person {
    public $name = "";
    public function __construct($n) {
        $this->name = $n;
    }
}
$p = new Person("Alice");
';
    $result = parseAndInline($code);
    expect($result)
        ->toContain('$p__name = ""')
        ->toContain('$p__name = "Alice"')
        ->not->toContain('new Person');
});

it('inlines method with multiple argument substitutions', function (): void {
    $code = '<?php
class Math {
    public function sum($a, $b) {
        return $a + $b;
    }
}
$m = new Math();
$r = $m->sum(3, 4);
';
    $result = parseAndInline($code);
    expect($result)
        ->toContain('3 + 4')
        ->not->toContain('$m->sum');
});

it('handles class with no properties (empty replacement)', function (): void {
    $code = '<?php
class Greeter {
    public function hello() {
        return "hi";
    }
}
$g = new Greeter();
$r = $g->hello();
';
    $result = parseAndInline($code);
    expect($result)
        ->not->toContain('new Greeter')
        ->not->toContain('$g->hello')
        ->toContain('"hi"');
});

it('inlines if→ternary method after TernarReturnVisitor', function (): void {
    $code = '<?php
class Auth {
    public function check($user, $pass) {
        if ($user === "admin") {
            return "ok";
        }
        return "fail";
    }
}
$a = new Auth();
$r = $a->check("admin", "secret");
';
    $result = parseAndInline($code, withTernar: true);
    expect($result)
        ->toContain('"admin" === "admin"')
        ->not->toContain('$a->check')
        ->not->toContain('class Auth');
});

it('skips method with more than one statement (no inlining)', function (): void {
    $code = '<?php
class Complex {
    public function multi($x) {
        $y = $x + 1;
        return $y * 2;
    }
}
$c = new Complex();
$r = $c->multi(5);
';
    $result = parseAndInline($code);
    // Method not inlined — call remains as-is
    expect($result)->toContain('$c->multi(5)');
});

it('inlines the same method twice independently', function (): void {
    $code = '<?php
class Auth {
    public function login($user, $pass) {
        return $user . "_" . $pass;
    }
}
$a = new Auth();
$r1 = $a->login($username, $myPass);
$r2 = $a->login("test", "test");
';
    $result = parseAndInline($code);
    expect($result)
        ->toContain('$username . "_" . $myPass')
        ->toContain('"test" . "_" . "test"')
        ->not->toContain('$a->login');
});

it('does not touch new ClassName() when class is unknown', function (): void {
    $code = '<?php
$x = new Unknown();
';
    $result = parseAndInline($code);
    expect($result)->toContain('new Unknown()');
});
