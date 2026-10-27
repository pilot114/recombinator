<?php

declare(strict_types=1);

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NodeConnectingVisitor;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as Printer;
use Recombinator\Transformation\Visitor\StaticMethodExtractVisitor;

function applyStaticExtract(string $code): string
{
    $ast = new ParserFactory()->createForNewestSupportedVersion()->parse($code) ?? [];
    $ast = new NodeTraverser(new NodeConnectingVisitor())->traverse($ast);
    $ast = new NodeTraverser(new StaticMethodExtractVisitor())->traverse($ast);

    return new Printer()->prettyPrint($ast);
}

it('moves static methods into functions and rewrites calls', function (): void {
    $result = applyStaticExtract(
        '<?php class DM { private const MAP = ["a" => 1]; private static $n = 0;
            public static function get(string $k) { self::$n++; return self::lookup($k) . __METHOD__; }
            private static function lookup(string $k) { return static::MAP[$k] ?? __CLASS__; } }
        echo DM::get("a"); $f = DM::get(...); $g = [DM::class, "get"]; $h = "DM::get";',
    );

    expect($result)
        ->toContain('function DM_get(string $k)')
        ->toContain('\DM::$n++;')
        ->toContain("return \\DM_lookup(\$k) . 'DM::get';")
        ->toContain("return \\DM::MAP[\$k] ?? 'DM';")
        ->toContain('echo \DM_get("a");')
        ->toContain('$f = \DM_get(...);')
        ->toContain("\$g = 'DM_get';")
        ->toContain("\$h = 'DM_get';")
        ->toContain('public const MAP')
        ->toContain('public static $n')
        ->not->toContain('static function');
});

it('is not blocked by a "*" string literal', function (): void {
    expect(applyStaticExtract('<?php class Q { static function cols() { return "*"; } } echo Q::cols();'))->toContain('function Q_cols()');
});

it('keeps static methods that cannot leave the class', function (string $code, string $kept): void {
    expect(applyStaticExtract($code))->toContain($kept);
})->with([
    'subclass'          => ['<?php class P { static function m() { return static::class; } } class C extends P {}', 'static function m()'],
    'interface'         => [
        '<?php interface I { static function m(); } class C implements I { static function m() { return 1; } }',
        'static function m()',
    ],
    'dynamic call'      => ['<?php class C { static function m() { return 1; } } $c = "C"; $c::m();', 'static function m()'],
    'private instance'  => [
        '<?php class C { private $v; static function make() { $x = new self(); $x->v = 1; return $x; } }',
        'static function make()',
    ],
    'method name used'  => ['<?php class C { static function m() { return 1; } } call_user_func([$cls, "m"]);', 'static function m()'],
]);

it('keeps a static method also called through an object', function (): void {
    expect(applyStaticExtract('<?php class P { static function all() { return []; } } $p = new P(); $p->all();'))
        ->toContain('static function all()');
});

it('leaves a forwarding wrapper when the method may be called dynamically', function (string $code): void {
    $result = applyStaticExtract($code);

    expect($result)->toContain('function C_m($a, ...$rest)')
        ->and($result)->toContain('return \C_m($a, ...$rest);')
        ->and($result)->toContain('echo \C_m(1);');
})->with([
    'string name'  => ['<?php class C { static function m($a, ...$rest) { return $a; } } echo C::m(1); $kw = ["M"];'],
    'object call'  => ['<?php class C { static function m($a, ...$rest) { return $a; } } echo C::m(1); $o->m(2);'],
    'interface'    => ['<?php interface I { static function m($a, ...$rest); } class C implements I { static function m($a, ...$rest) { return $a; } } echo C::m(1);'],
]);

it('extracts inherited static methods and resolves calls through descendants', function (): void {
    $result = applyStaticExtract(
        '<?php class P { static function make($v) { return self::wrap($v); } private static function wrap($v) { return [$v]; } }
        class C extends P { static function build() { return parent::make(1) + self::make(2); } }
        echo C::make(3), P::make(4);',
    );

    expect($result)->toContain('function P_make($v)')
        ->and($result)->toContain('return \P_make(1) + \P_make(2);')
        ->and($result)->toContain('echo \P_make(3), \P_make(4);')
        ->and($result)->toContain('return \P_make($v);');
});

it('keeps inherited static methods bound late or overridden', function (string $code): void {
    expect(applyStaticExtract($code))->not->toContain('function P_m(');
})->with([
    'late binding' => ['<?php class P { static function m() { return new static(); } } class C extends P {} C::m();'],
    'overridden'   => ['<?php class P { static function m() { return 1; } } class C extends P { static function m() { return 2; } } P::m();'],
    'arg list'     => ['<?php class P { static function m() { return func_get_args(); } } class C extends P {} P::m(1);'],
]);
