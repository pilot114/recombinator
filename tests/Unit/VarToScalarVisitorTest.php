<?php

declare(strict_types=1);

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;
use Recombinator\Domain\ScopeStore;
use Recombinator\Transformation\Visitor\ScopeVisitor;
use Recombinator\Transformation\Visitor\VarToScalarVisitor;

beforeEach(
    function (): void {
        $this->parser = new ParserFactory()->createForHostVersion();
        $this->printer = new StandardPrinter();
        $this->store = new ScopeStore();
        $this->visitor = new VarToScalarVisitor($this->store);
    }
);

it(
    'can replace scalar variable with its value',
    function (): void {
        $code = '<?php
$test = 42;
echo $test;';

        $ast = $this->parser->parse($code);
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ParentConnectingVisitor());
        $traverser->addVisitor(new ScopeVisitor());
        $traverser->addVisitor($this->visitor);

        $ast = $traverser->traverse($ast);

        $result = $this->printer->prettyPrint($ast);

        expect($result)->toContain('echo 42')
            ->and($result)->not->toContain('$test = 42');
    }
);

it(
    'can replace string variable with its value',
    function (): void {
        $code = '<?php
$name = "John";
echo $name;';

        $ast = $this->parser->parse($code);
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ParentConnectingVisitor());
        $traverser->addVisitor(new ScopeVisitor());
        $traverser->addVisitor($this->visitor);

        $ast = $traverser->traverse($ast);

        $result = $this->printer->prettyPrint($ast);

        expect($result)->toContain("echo 'John'");
    }
);

it(
    'can replace boolean variable with its value',
    function (): void {
        $code = '<?php
$flag = true;
if ($flag) {
    echo "yes";
}';

        $ast = $this->parser->parse($code);
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ParentConnectingVisitor());
        $traverser->addVisitor(new ScopeVisitor());
        $traverser->addVisitor($this->visitor);

        $ast = $traverser->traverse($ast);

        $result = $this->printer->prettyPrint($ast);

        expect($result)->toContain('if (true)');
    }
);

it(
    'can replace float variable with its value',
    function (): void {
        $code = '<?php
$pi = 3.14;
echo $pi;';

        $ast = $this->parser->parse($code);
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ParentConnectingVisitor());
        $traverser->addVisitor(new ScopeVisitor());
        $traverser->addVisitor($this->visitor);

        $ast = $traverser->traverse($ast);

        $result = $this->printer->prettyPrint($ast);

        expect($result)->toContain('echo 3.14');
    }
);

it(
    'should not replace non-scalar variable',
    function (): void {
        $code = '<?php
$arr = [1, 2, 3];
echo $arr[0];';

        $ast = $this->parser->parse($code);
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ParentConnectingVisitor());
        $traverser->addVisitor(new ScopeVisitor());
        $traverser->addVisitor($this->visitor);

        $ast = $traverser->traverse($ast);

        $result = $this->printer->prettyPrint($ast);

        expect($result)->toContain('$arr = [1, 2, 3]');
        expect($result)->toContain('echo $arr[0]');
    }
);

it(
    'should clear variable from cache when reassigned with non-scalar',
    function (): void {
        $code = '<?php
$val = 10;
echo $val;
$val = [1, 2, 3];
echo $val[0];';

        $ast = $this->parser->parse($code);
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ParentConnectingVisitor());
        $traverser->addVisitor(new ScopeVisitor());
        $traverser->addVisitor($this->visitor);

        $ast = $traverser->traverse($ast);

        $result = $this->printer->prettyPrint($ast);

        // First echo should be replaced
        expect($result)->toContain('echo 10');
        // Second assignment and echo should remain
        expect($result)->toContain('$val = [1, 2, 3]');
        expect($result)->toContain('echo $val[0]');
    }
);

it(
    'can replace multiple variables',
    function (): void {
        $code = '<?php
$a = 5;
$b = 10;
$c = 15;
echo $a + $b + $c;';

        $ast = $this->parser->parse($code);
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ParentConnectingVisitor());
        $traverser->addVisitor(new ScopeVisitor());
        $traverser->addVisitor($this->visitor);

        $ast = $traverser->traverse($ast);

        $result = $this->printer->prettyPrint($ast);

        expect($result)->toContain('echo 5 + 10 + 15');
    }
);

it(
    'should not replace variable in left side of assignment',
    function (): void {
        $code = '<?php
$test = 10;
$test = 20;
echo $test;';

        $ast = $this->parser->parse($code);
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ParentConnectingVisitor());
        $traverser->addVisitor(new ScopeVisitor());
        $traverser->addVisitor($this->visitor);

        $ast = $traverser->traverse($ast);

        $result = $this->printer->prettyPrint($ast);

        // Should use the last value
        expect($result)->toContain('echo 20');
    }
);

it(
    'stores scalar variable in scope',
    function (): void {
        $code = '<?php $test = 100;';

        $ast = $this->parser->parse($code);
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ParentConnectingVisitor());
        $traverser->addVisitor(new ScopeVisitor());
        $traverser->addVisitor($this->visitor);

        $ast = $traverser->traverse($ast);

        $variable = $this->store->getVarFromScope('test');

        expect($variable)->not->toBeNull()
            ->and($variable->value)->toBe(100);
    }
);

it(
    'can handle variable used in concatenation',
    function (): void {
        $code = '<?php
$name = "World";
echo "Hello, " . $name;';

        $ast = $this->parser->parse($code);
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ParentConnectingVisitor());
        $traverser->addVisitor(new ScopeVisitor());
        $traverser->addVisitor($this->visitor);

        $ast = $traverser->traverse($ast);

        $result = $this->printer->prettyPrint($ast);

        expect($result)->toContain('Hello, " . \'World');
    }
);

it(
    'should not replace variable with scalar when variable is reassigned inside loop',
    function (): void {
        $code = '<?php
$subtotal = 0;
foreach ($items as $item) {
    $subtotal = $subtotal + $item[\'price\'];
}';

        $ast = $this->parser->parse($code);
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ParentConnectingVisitor());
        $traverser->addVisitor(new ScopeVisitor());
        $traverser->addVisitor($this->visitor);

        $ast = $traverser->traverse($ast);

        $result = $this->printer->prettyPrint($ast);

        // $subtotal = 0 должно остаться (переменная нужна в цикле)
        expect($result)->toContain('$subtotal = 0');
        // внутри цикла $subtotal не должен заменяться на скаляр
        expect($result)->toContain('$subtotal = $subtotal + $item[\'price\']');
    }
);

it('does not substitute variables changed through a reference', function (): void {
    $code = '<?php function inc(&$n) { $n++; } $n = 0; inc($n); echo $n;';
    $ast  = new PhpParser\ParserFactory()->createForNewestSupportedVersion()->parse($code) ?? [];
    $ast  = new PhpParser\NodeTraverser(new PhpParser\NodeVisitor\NodeConnectingVisitor())->traverse($ast);
    $ast  = new PhpParser\NodeTraverser(
        new Recombinator\Transformation\Visitor\VarToScalarVisitor(new Recombinator\Domain\ScopeStore()),
    )->traverse($ast);

    expect(new PhpParser\PrettyPrinter\Standard()->prettyPrint($ast))->toContain('echo $n;');
});

it('keeps variables in binding positions (closure use, isset, unset, foreach)', function (): void {
    $code = '<?php $s = "a{$x}b"; $f = function () use ($s) { return $s; }; $i = isset($s); foreach ([1] as $s) {} unset($s);';

    $traverser = new NodeTraverser();
    $traverser->addVisitor(new ParentConnectingVisitor());
    $traverser->addVisitor(new ScopeVisitor());
    $traverser->addVisitor($this->visitor);

    $result = $this->printer->prettyPrint($traverser->traverse($this->parser->parse($code) ?? []));

    expect($result)->toContain('use ($s)')
        ->and($result)->toContain('isset($s)')
        ->and($result)->toContain('as $s')
        ->and($result)->toContain('unset($s)');
});

it('does not treat interpolated strings as constants', function (): void {
    $code = '<?php $indent = f(); $sub = "{$indent}  "; $indent = "x"; echo "a{$sub}b";';

    $traverser = new NodeTraverser();
    $traverser->addVisitor(new ParentConnectingVisitor());
    $traverser->addVisitor(new ScopeVisitor());
    $traverser->addVisitor($this->visitor);

    $result = $this->printer->prettyPrint($traverser->traverse($this->parser->parse($code) ?? []));

    expect($result)->toContain('$sub = "{$indent}  ";')
        ->and($result)->toContain('echo "a{$sub}b";');
});

it('does not carry values between function bodies or into parameters', function (): void {
    $code = '<?php function a() { $c = "X"; return $c; } function b(string $c) { return $c; }';

    $traverser = new NodeTraverser();
    $traverser->addVisitor(new ParentConnectingVisitor());
    $traverser->addVisitor(new ScopeVisitor());
    $traverser->addVisitor($this->visitor);

    $result = $this->printer->prettyPrint($traverser->traverse($this->parser->parse($code) ?? []));

    expect($result)->toContain('function b(string $c)')
        ->and($result)->toContain('return $c;');
});
