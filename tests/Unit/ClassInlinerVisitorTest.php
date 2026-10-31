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

it('inlines statement-level procedure call (method without return)', function (): void {
    $code = '<?php
class Box {
    private array $items = [];
    public function set(string $k, $v): void { $this->items[$k] = $v; }
    public function get(string $k) { return $this->items[$k]; }
}
$b = new Box();
$b->set("a", f());
echo $b->get("a");';
    $result = parseAndInline($code);
    expect($result)
        ->not->toContain('class Box')
        ->toMatch('/\$set_v_(\d+) = f\(\);\s+\$b__items\["a"\] = \$set_v_\1;/')
        ->toContain('echo $b__items["a"];');
});

it('renames procedure locals so they do not clash with caller variables', function (): void {
    $code = '<?php
class Acc {
    private $sum = 0;
    public function add($x) { $tmp = $x * 2; $this->sum = $this->sum + $tmp; }
    public function total() { return $this->sum; }
}
$tmp = 100;
$a = new Acc();
$a->add(1);
echo $a->total() + $tmp;';
    $result = parseAndInline($code);
    expect($result)
        ->not->toContain('class Acc')
        ->toMatch('/\$add_tmp_(\d+) = 1 \* 2;\s+\$a__sum = \$a__sum \+ \$add_tmp_\1;/')
        ->toContain('echo $a__sum + $tmp;');
});

it('keeps class when procedure leaks $this', function (): void {
    $code = '<?php
class Reg {
    public function register($p) { $p->provide($this); }
}
$r = new Reg();
$r->register($x);';
    $result = parseAndInline($code);
    expect($result)->toContain('class Reg');
});

it('keeps class when procedure has early return', function (): void {
    $code = '<?php
class G {
    private $v = 0;
    public function set($x) { if ($x) { return; } $this->v = 1; }
}
$g = new G();
$g->set(1);';
    $result = parseAndInline($code);
    expect($result)->toContain('class G');
});

it('allows return inside closures in procedure body', function (): void {
    $code = '<?php
class C {
    private array $f = [];
    public function set($id) { $this->f[$id] = static function () { return 1; }; }
}
$c = new C();
$c->set("x");';
    $result = parseAndInline($code);
    expect($result)
        ->not->toContain('class C')
        ->toContain('$c__f["x"] = static function () {');
});

it('keeps the class while it is referenced by name', function (): void {
    $code = '<?php
class Auth {
    const HASH = "h";
    public $user = "u";
}
$a = new Auth();
echo $a->user . Auth::HASH;
';
    expect(parseAndInline($code))->toContain('class Auth')
        ->toContain('Auth::HASH');
});
