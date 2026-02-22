<?php

declare(strict_types=1);

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;
use Recombinator\Transformation\Visitor\ConcatInterpolateVisitor;

function applyInterpolate(string $code): string
{
    $parser  = new ParserFactory()->createForHostVersion();
    $printer = new StandardPrinter();

    $ast = $parser->parse($code) ?? [];

    $t = new NodeTraverser();
    $t->addVisitor(new ConcatInterpolateVisitor());

    $ast = $t->traverse($ast);

    return $printer->prettyPrint($ast);
}

// ── Basic string · variable · string ─────────────────────────────────────────

it('converts string . var . string to interpolated', function (): void {
    $result = applyInterpolate('<?php echo \'prefix\' . $var . \'suffix\';');

    expect($result)->toContain('"prefix{$var}suffix"');
});

it('converts string . var (no trailing string)', function (): void {
    $result = applyInterpolate('<?php echo \'prefix\' . $var;');

    expect($result)->toContain('"prefix{$var}"');
});

it('converts var . string (no leading string)', function (): void {
    $result = applyInterpolate('<?php echo $var . \' suffix\';');

    expect($result)->toContain('"{$var} suffix"');
});

// ── Escape sequences ──────────────────────────────────────────────────────────

it('embeds double-quoted escape sequence into interpolated string', function (): void {
    $result = applyInterpolate('<?php echo $var . "\n";');

    expect($result)->toContain('"{$var}\n"');
});

it('handles the motivating example with nested concat', function (): void {
    // echo '1234510.231test_test' . ($tmp3 . "\n") . 'success'
    $code = '<?php echo \'1234510.231test_test\' . ($tmp3 . "\n") . \'success\';';

    $result = applyInterpolate($code);

    expect($result)->toContain('"1234510.231test_test{$tmp3}');
    expect($result)->toContain('success"');
    // Single expression: no outer concatenation operator left
    expect($result)->not->toContain(' . $tmp3');
    expect($result)->not->toContain('$tmp3 .');
});

// ── Multiple variables ────────────────────────────────────────────────────────

it('handles multiple variables', function (): void {
    $result = applyInterpolate('<?php echo "a" . $x . "b" . $y . "c";');

    expect($result)->toContain('"a{$x}b{$y}c"');
});

// ── Already-encapsed strings ──────────────────────────────────────────────────

it('merges already-interpolated string with another variable', function (): void {
    // "{$x}foo" . $y  →  "{$x}foo{$y}"
    $result = applyInterpolate('<?php echo "{$x}foo" . $y;');

    expect($result)->toContain('"{$x}foo{$y}"');
});

// ── Non-flattenable — must NOT transform ─────────────────────────────────────

it('does NOT transform when concat contains a function call', function (): void {
    $result = applyInterpolate('<?php echo foo() . "bar";');

    expect($result)->toContain('foo()');
    expect($result)->not->toContain('"{$');
});

it('does NOT transform when concat contains a method call', function (): void {
    $result = applyInterpolate('<?php echo $obj->method() . "bar";');

    expect($result)->toContain('->method()');
    expect($result)->not->toContain('"{$');
});

// ── Assignment context ────────────────────────────────────────────────────────

it('also transforms concat in assignment RHS', function (): void {
    $result = applyInterpolate('<?php $s = "Hello, " . $name . "!";');

    expect($result)->toContain('"Hello, {$name}!"');
});
