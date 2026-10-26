<?php

declare(strict_types=1);

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NodeConnectingVisitor;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;
use Recombinator\Transformation\Visitor\ReadabilityVisitor;

function applyReadability(string $code): string
{
    $parser  = new ParserFactory()->createForHostVersion();
    $printer = new StandardPrinter();

    $ast = $parser->parse($code) ?? [];

    $t1 = new NodeTraverser();
    $t1->addVisitor(new NodeConnectingVisitor());

    $ast = $t1->traverse($ast);

    $t2 = new NodeTraverser();
    $t2->addVisitor(new ReadabilityVisitor());

    $ast = $t2->traverse($ast);

    return $printer->prettyPrint($ast);
}

// ── Basic extraction ──────────────────────────────────────────────────────────

it('extracts ternary nested inside echo concat', function (): void {
    $code   = '<?php echo "prefix" . ($a === "x" ? "ok" : "fail") . "\n";';
    $result = applyReadability($code);

    // Assignment introduced before the echo
    expect($result)->toContain('$tmp1 =');
    expect($result)->toContain('? "ok"');
    // Original echo no longer contains the ternary directly
    expect($result)->toContain('echo "prefix" . $tmp1');
});

it('extracts ternary from function call argument', function (): void {
    $code   = '<?php foo($a > 0 ? "pos" : "neg");';
    $result = applyReadability($code);

    expect($result)->toContain('$tmp1 =');
    expect($result)->toContain('foo($tmp1)');
});

it('extracts ternary from array element', function (): void {
    $code   = '<?php $arr = [$a ? "x" : "y", "z"];';
    $result = applyReadability($code);

    expect($result)->toContain('$tmp1 =');
    expect($result)->toContain('[$tmp1, "z"]');
});

// ── Skip already-readable top-level ternaries ─────────────────────────────────

it('does NOT extract ternary that is direct RHS of assignment', function (): void {
    $code   = '<?php $x = $a === "test" ? "ok" : "fail";';
    $result = applyReadability($code);

    // No tmp variable introduced
    expect($result)->not->toContain('$tmp');
    expect($result)->toContain('$x = $a');
});

it('does NOT extract ternary that is sole expression of echo', function (): void {
    $code   = '<?php echo $a ? "yes" : "no";';
    $result = applyReadability($code);

    expect($result)->not->toContain('$tmp');
    expect($result)->toContain('echo $a ?');
});

it('does NOT extract ternary that is direct return value', function (): void {
    $code   = '<?php function f($a) { return $a > 0 ? "pos" : "neg"; }';
    $result = applyReadability($code);

    expect($result)->not->toContain('$tmp');
    expect($result)->toContain('return $a > 0');
});

// ── Nested ternaries extracted innermost-first ────────────────────────────────

it('extracts nested ternary from condition innermost first, but not from branches', function (): void {
    // Ветка тернарника вычисляется по условию — её нельзя выносить перед оператором
    $code   = '<?php echo (($y ? $a : $b) ? ($z ? "a" : "b") : "c") . "\n";';
    $result = applyReadability($code);

    expect($result)->toContain('$tmp1 = $y ? $a : $b;');
    expect($result)->toContain('$tmp2 = $tmp1 ? $z ? "a" : "b" : "c";');
    expect($result)->toContain('echo $tmp2');
});

// ── Multiple statements — counter increments ──────────────────────────────────

it('increments counter across multiple statements', function (): void {
    $code = '<?php
echo "a" . ($x ? "b" : "c") . "d";
echo "e" . ($y ? "f" : "g") . "h";
';
    $result = applyReadability($code);

    expect($result)->toContain('$tmp1 =');
    expect($result)->toContain('$tmp2 =');
    expect($result)->not->toContain('$tmp3');
});

// ── Coalesce (??) extraction ─────────────────────────────────────────────────

it('extracts ?? nested inside concat', function (): void {
    $code   = '<?php echo ($_GET["username"] ?? "default") . "\n";';
    $result = applyReadability($code);

    expect($result)->toContain('$tmp1 = $_GET');
    expect($result)->toContain('?? "default"');
    expect($result)->toContain('echo $tmp1');
});

it('does NOT extract ?? that is direct RHS of assignment', function (): void {
    $code   = '<?php $x = $_GET["username"] ?? "default";';
    $result = applyReadability($code);

    expect($result)->not->toContain('$tmp');
    expect($result)->toContain('$x = $_GET');
});

it('does NOT extract ?? that is sole expression of echo', function (): void {
    $code   = '<?php echo $_GET["x"] ?? "default";';
    $result = applyReadability($code);

    expect($result)->not->toContain('$tmp');
});

it('extracts multiple ?? and ternary from complex expression in correct order', function (): void {
    // The motivating example from the task
    $code = '<?php echo ($_GET["username"] ?? "default_username") . "_" . ($_GET["pass"] ?? "default_pass") . "\n";';
    $result = applyReadability($code);

    // Both coalesces extracted
    expect($result)->toContain('$tmp1 =');
    expect($result)->toContain('$tmp2 =');
    expect($result)->toContain('echo $tmp1 . "_" . $tmp2');
});

// ── The primary motivating example ───────────────────────────────────────────

it('extracts ternary from complex echo expression', function (): void {
    $code = "<?php echo '123' . ((\$a . '_' . \$b === 'test' ? 'success' : 'fail') . \"\\n\") . 'end';";
    $result = applyReadability($code);

    expect($result)->toContain('$tmp1 =');
    expect($result)->toContain("'success'");
    expect($result)->toContain("'fail'");
    // The echo now uses $tmp1 instead of the ternary directly
    expect($result)->not->toMatch('/echo.*\?.*:/');
});

it('does NOT hoist ternary out of a closure body', function (): void {
    $code   = '<?php $c->set("em", static function ($c) { return new EM($c->get("s") ? new A() : new B()); });';
    $result = applyReadability($code);
    expect($result)->toMatch('/function \(\$c\) \{\s+\$tmp1 = \$c->get\("s"\) \? new A\(\) : new B\(\);\s+return new EM\(\$tmp1\);/');
});

it('does NOT hoist ternary from conditionally evaluated operand', function (): void {
    $code   = '<?php f($ok && g($a ? h() : i()), $x ?? ($y ? 1 : 2));';
    $result = applyReadability($code);
    expect($result)
        ->toContain('$tmp1 = $x ?? ($y ? 1 : 2);')
        ->toContain('f($ok && g($a ? h() : i()), $tmp1);')
        ->not->toContain('$tmp2');
});

it('does NOT hoist ternary nested in a ternary branch', function (): void {
    $code   = '<?php echo "x" . ($a ? ($b ? f() : g()) : 0);';
    $result = applyReadability($code);
    expect($result)
        ->toContain('$tmp1 = $a ? $b ? f() : g() : 0;')
        ->not->toContain('$tmp2');
});
