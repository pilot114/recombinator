<?php

declare(strict_types=1);

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NodeConnectingVisitor;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;
use Recombinator\Transformation\Visitor\RemoveUnusedPureVisitor;

beforeEach(function (): void {
    $this->parser  = new ParserFactory()->createForHostVersion();
    $this->printer = new StandardPrinter();
    $this->visitor = new RemoveUnusedPureVisitor();
});

function applyUnusedPure(string $code, RemoveUnusedPureVisitor $visitor): string
{
    $parser  = new ParserFactory()->createForHostVersion();
    $printer = new StandardPrinter();
    $ast     = $parser->parse($code) ?? [];

    $t1 = new NodeTraverser();
    $t1->addVisitor(new NodeConnectingVisitor());
    $ast = $t1->traverse($ast);

    $t2 = new NodeTraverser();
    $t2->addVisitor($visitor);
    $ast = $t2->traverse($ast);

    return $printer->prettyPrint($ast);
}

it('removes pure binary expression statement', function (): void {
    $result = applyUnusedPure('<?php $a + $b;', $this->visitor);
    expect($result)->toBe('');
});

it('removes pure scalar statement', function (): void {
    $result = applyUnusedPure('<?php 1 + 2 + 3;', $this->visitor);
    expect($result)->toBe('');
});

it('removes assignment to variable never read when RHS is pure', function (): void {
    $code   = '<?php $unused = $x * 2;';
    $result = applyUnusedPure($code, $this->visitor);
    expect($result)->toBe('');
});

it('keeps assignment when variable is read later', function (): void {
    $code   = '<?php $val = $x + 1; echo $val;';
    $result = applyUnusedPure($code, $this->visitor);
    expect($result)->toContain('$val = $x + 1');
    expect($result)->toContain('echo $val');
});

it('removes known-pure function call statement when result is unused', function (): void {
    // strlen — в whitelist чистых функций; результат отброшен → удалить
    $result = applyUnusedPure('<?php strlen("hello");', $this->visitor);
    expect($result)->toBe('');
});

it('removes assignment to unused var when RHS is known-pure function', function (): void {
    $code   = '<?php $result = strlen("hello");';
    $result = applyUnusedPure($code, $this->visitor);
    expect($result)->toBe('');
});

it('keeps unknown function call statement (may have side effects)', function (): void {
    $result = applyUnusedPure('<?php myCustomFunc("hello");', $this->visitor);
    expect($result)->toContain('myCustomFunc');
});

it('keeps assignment where RHS is an unknown function call', function (): void {
    $code   = '<?php $result = myCustomFunc("hello");';
    $result = applyUnusedPure($code, $this->visitor);
    expect($result)->toContain('myCustomFunc');
});

it('removes pure ternary expression statement', function (): void {
    $result = applyUnusedPure('<?php $a > 0 ? $a : 0;', $this->visitor);
    expect($result)->toBe('');
});

it('removes unused pure assignment with scalar', function (): void {
    $code   = '<?php $dead = 42;';
    $result = applyUnusedPure($code, $this->visitor);
    expect($result)->toBe('');
});

it('removes assignment to unused var when RHS is boolean-not', function (): void {
    $code   = '<?php $n = !false;';
    $result = applyUnusedPure($code, $this->visitor);
    expect($result)->toBe('');
});

it('removes boolean-not expression statement', function (): void {
    $result = applyUnusedPure('<?php !true;', $this->visitor);
    expect($result)->toBe('');
});
