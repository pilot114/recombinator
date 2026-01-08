<?php

declare(strict_types=1);

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;
use Recombinator\Domain\ScopeStore;
use Recombinator\Transformation\Visitor\ScopeVisitor;
use Recombinator\Transformation\Visitor\VarToScalarVisitor;
use Recombinator\Transformation\Visitor\RemoveVisitor;

beforeEach(function () {
    $this->parser = (new ParserFactory())->createForHostVersion();
    $this->printer = new StandardPrinter();
    $this->store = new ScopeStore();
    $this->visitor = new VarToScalarVisitor($this->store);
});

it('can replace scalar variable with its value', function () {
    $code = '<?php
$test = 42;
echo $test;';

    $ast = $this->parser->parse($code);
    $traverser = new NodeTraverser();
    $traverser->addVisitor(new ScopeVisitor());
    $traverser->addVisitor($this->visitor);
    $traverser->addVisitor(new RemoveVisitor());
    $ast = $traverser->traverse($ast);

    $result = $this->printer->prettyPrint($ast);

    expect($result)->toContain('echo 42');
    expect($result)->not->toContain('$test = 42');
});

it('can replace string variable with its value', function () {
    $code = '<?php
$name = "John";
echo $name;';

    $ast = $this->parser->parse($code);
    $traverser = new NodeTraverser();
    $traverser->addVisitor(new ScopeVisitor());
    $traverser->addVisitor($this->visitor);
    $traverser->addVisitor(new RemoveVisitor());
    $ast = $traverser->traverse($ast);

    $result = $this->printer->prettyPrint($ast);

    expect($result)->toContain("echo 'John'");
});

it('can replace boolean variable with its value', function () {
    $code = '<?php
$flag = true;
if ($flag) {
    echo "yes";
}';

    $ast = $this->parser->parse($code);
    $traverser = new NodeTraverser();
    $traverser->addVisitor(new ScopeVisitor());
    $traverser->addVisitor($this->visitor);
    $traverser->addVisitor(new RemoveVisitor());
    $ast = $traverser->traverse($ast);

    $result = $this->printer->prettyPrint($ast);

    expect($result)->toContain('if (true)');
});

it('can replace float variable with its value', function () {
    $code = '<?php
$pi = 3.14;
echo $pi;';

    $ast = $this->parser->parse($code);
    $traverser = new NodeTraverser();
    $traverser->addVisitor(new ScopeVisitor());
    $traverser->addVisitor($this->visitor);
    $traverser->addVisitor(new RemoveVisitor());
    $ast = $traverser->traverse($ast);

    $result = $this->printer->prettyPrint($ast);

    expect($result)->toContain('echo 3.14');
});

it('should not replace non-scalar variable', function () {
    $code = '<?php
$arr = [1, 2, 3];
echo $arr[0];';

    $ast = $this->parser->parse($code);
    $traverser = new NodeTraverser();
    $traverser->addVisitor(new ScopeVisitor());
    $traverser->addVisitor($this->visitor);
    $ast = $traverser->traverse($ast);

    $result = $this->printer->prettyPrint($ast);

    expect($result)->toContain('$arr = [1, 2, 3]');
    expect($result)->toContain('echo $arr[0]');
});

it('should clear variable from cache when reassigned with non-scalar', function () {
    $code = '<?php
$val = 10;
echo $val;
$val = [1, 2, 3];
echo $val[0];';

    $ast = $this->parser->parse($code);
    $traverser = new NodeTraverser();
    $traverser->addVisitor(new ScopeVisitor());
    $traverser->addVisitor($this->visitor);
    $traverser->addVisitor(new RemoveVisitor());
    $ast = $traverser->traverse($ast);

    $result = $this->printer->prettyPrint($ast);

    // First echo should be replaced
    expect($result)->toContain('echo 10');
    // Second assignment and echo should remain
    expect($result)->toContain('$val = [1, 2, 3]');
    expect($result)->toContain('echo $val[0]');
});

it('can replace multiple variables', function () {
    $code = '<?php
$a = 5;
$b = 10;
$c = 15;
echo $a + $b + $c;';

    $ast = $this->parser->parse($code);
    $traverser = new NodeTraverser();
    $traverser->addVisitor(new ScopeVisitor());
    $traverser->addVisitor($this->visitor);
    $traverser->addVisitor(new RemoveVisitor());
    $ast = $traverser->traverse($ast);

    $result = $this->printer->prettyPrint($ast);

    expect($result)->toContain('echo 5 + 10 + 15');
});

it('should not replace variable in left side of assignment', function () {
    $code = '<?php
$test = 10;
$test = 20;
echo $test;';

    $ast = $this->parser->parse($code);
    $traverser = new NodeTraverser();
    $traverser->addVisitor(new ScopeVisitor());
    $traverser->addVisitor($this->visitor);
    $traverser->addVisitor(new RemoveVisitor());
    $ast = $traverser->traverse($ast);

    $result = $this->printer->prettyPrint($ast);

    // Should use the last value
    expect($result)->toContain('echo 20');
});

it('stores scalar variable in scope', function () {
    $code = '<?php $test = 100;';

    $ast = $this->parser->parse($code);
    $traverser = new NodeTraverser();
    $traverser->addVisitor(new ScopeVisitor());
    $traverser->addVisitor($this->visitor);
    $ast = $traverser->traverse($ast);

    $variable = $this->store->getVarFromScope('test');

    expect($variable)->not->toBeNull()
        ->and($variable->value)->toBe(100);
});

it('can handle variable used in concatenation', function () {
    $code = '<?php
$name = "World";
echo "Hello, " . $name;';

    $ast = $this->parser->parse($code);
    $traverser = new NodeTraverser();
    $traverser->addVisitor(new ScopeVisitor());
    $traverser->addVisitor($this->visitor);
    $traverser->addVisitor(new RemoveVisitor());
    $ast = $traverser->traverse($ast);

    $result = $this->printer->prettyPrint($ast);

    expect($result)->toContain("'Hello, ' . 'World'");
});
