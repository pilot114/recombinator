<?php

declare(strict_types=1);

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as Printer;
use Recombinator\Analysis\PurityAnalyzer;
use Recombinator\Transformation\Visitor\PureCallEvaluatorVisitor;

function pureCalls(string $code): string
{
    $ast = new ParserFactory()->createForNewestSupportedVersion()->parse('<?php ' . $code) ?? [];
    $ast = new NodeTraverser(new ParentConnectingVisitor())->traverse($ast);

    return new Printer()->prettyPrint(new NodeTraverser(new PureCallEvaluatorVisitor())->traverse($ast));
}

function purity(string $code): PurityAnalyzer
{
    $ast     = new ParserFactory()->createForNewestSupportedVersion()->parse('<?php ' . $code) ?? [];
    $ast     = new NodeTraverser(new ParentConnectingVisitor())->traverse($ast);

    $classes = [];
    foreach ($ast as $stmt) {
        if ($stmt instanceof PhpParser\Node\Stmt\ClassLike && $stmt->name instanceof \PhpParser\Node\Identifier) {
            $classes[$stmt->name->toLowerString()] = $stmt;
        }
    }

    return new PurityAnalyzer($classes, $ast);
}

it('classifies purity transitively', function (): void {
    $p = purity('
        class Inflector { public static function tableize(string $s): string { return strtolower(preg_replace("/(?<!^)[A-Z]/", "_$0", $s)); }
                          public static function log(string $s): void { echo $s; }
                          public static function both(string $s): string { self::log($s); return self::tableize($s); } }
        class Counter { private static int $n = 0; public static function next(): int { return ++self::$n; } }
        class Clock { public function now(): int { return time(); } }
        function f($x) { return Inflector::tableize($x); }
        function g() { return mt_rand(); }');

    expect($p->isPure('inflector::tableize'))->toBeTrue()
        ->and($p->isPure('fn:f'))->toBeTrue()
        ->and($p->isPure('inflector::log'))->toBeFalse()
        ->and($p->isPure('inflector::both'))->toBeFalse()
        ->and($p->isPure('counter::next'))->toBeFalse()
        ->and($p->isPure('clock::now'))->toBeFalse()
        ->and($p->isPure('fn:g'))->toBeFalse();
});

it('evaluates pure static methods, functions and fresh-object chains', function (): void {
    $result = pureCalls('
        class Inflector { public static function tableize(string $s): string { return strtolower(preg_replace("/(?<!^)[A-Z]/", "_$0", $s)); } }
        class Parser { private array $parts; public function __construct(string $route) { $this->parts = explode("/", trim($route, "/")); }
                       public function variables(): array { return array_values(array_filter($this->parts, fn($p) => $p[0] === "{")); } }
        function routeName(string $m, string $p): string { return strtolower($m) . "_" . Inflector::tableize(trim($p, "/")); }
        $a = Inflector::tableize("UserProfile");
        $b = (new Parser("/users/{id}"))->variables();
        $c = routeName("GET", "/Users");
        $d = Inflector::tableize($input);');

    expect($result)->toContain("\$a = 'user_profile';")
        ->toContain("\$b = [0 => '{id}'];")
        ->toContain("\$c = 'get_users';")
        ->toContain('$d = Inflector::tableize($input);');
});

it('keeps impure and throwing calls', function (): void {
    $result = pureCalls('
        class R { public static function rnd(): int { return mt_rand(); } public static function fail(): int { throw new LogicException("x"); }
                  public static function obj(): object { return new ArrayObject([]); } }
        $a = R::rnd(); $b = R::fail(); $c = R::obj();');

    expect($result)->toContain('R::rnd()')->toContain('R::fail()')->toContain('R::obj()');
});

it('treats existence checks of computed names as impure', function (): void {
    $p = purity('
        class A { public static function has(string $c): bool { return class_exists($c); }
                  public static function hasA(): bool { return class_exists("A"); }
                  public static function sub(string $c): bool { return is_a($c, "A", true); } }');

    expect($p->isPure('a::has'))->toBeFalse()->and($p->isPure('a::hasa'))->toBeTrue()->and($p->isPure('a::sub'))->toBeFalse();
});
