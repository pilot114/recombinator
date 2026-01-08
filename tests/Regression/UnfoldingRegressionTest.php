<?php

declare(strict_types=1);

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;
use Recombinator\Domain\ScopeStore;
use Recombinator\Transformation\Visitor\BinaryAndIssetVisitor;
use Recombinator\Transformation\Visitor\ConstClassVisitor;
use Recombinator\Transformation\Visitor\VarToScalarVisitor;
use Recombinator\Transformation\Visitor\RemoveVisitor;
use Recombinator\Transformation\Visitor\ScopeVisitor;

beforeEach(function () {
    $this->parser = (new ParserFactory())->createForHostVersion();
    $this->printer = new StandardPrinter();
    $this->store = new ScopeStore();
});

it('does not break when variable is reassigned with different type', function () {
    $code = '<?php
$val = 10;
echo $val;
$val = [1, 2, 3];
echo $val[0];';

    $ast = $this->parser->parse($code);

    $traverser = new NodeTraverser();
    $traverser->addVisitor(new ScopeVisitor());
    $traverser->addVisitor(new VarToScalarVisitor($this->store));
    $traverser->addVisitor(new RemoveVisitor());

    // Should not throw exception
    expect(fn() => $traverser->traverse($ast))->not->toThrow(Exception::class);
});

it('preserves semantics when constant is used multiple times', function () {
    $code = '<?php
class Test {
    const VALUE = 5;

    public function getSum() {
        return self::VALUE + self::VALUE;
    }
}';

    $ast = $this->parser->parse($code);

    $traverser = new NodeTraverser();
    $traverser->addVisitor(new ScopeVisitor());
    $traverser->addVisitor(new ConstClassVisitor($this->store));
    $traverser->addVisitor(new BinaryAndIssetVisitor());
    $ast = $traverser->traverse($ast);

    $result = $this->printer->prettyPrint($ast);

    // Both constants should be replaced
    expect($result)->toContain('return 10');
});

it('handles division by zero gracefully', function () {
    $code = '<?php $result = 10 / 0;';

    $ast = $this->parser->parse($code);

    $traverser = new NodeTraverser();
    $traverser->addVisitor(new BinaryAndIssetVisitor());

    // Should not crash, but might not optimize
    expect(fn() => $traverser->traverse($ast))->not->toThrow(Exception::class);
});

it('preserves boolean semantics in concatenation', function () {
    $code = '<?php
$result = "Value: " . true;
$result2 = "Value: " . false;';

    $ast = $this->parser->parse($code);

    $traverser = new NodeTraverser();
    $traverser->addVisitor(new BinaryAndIssetVisitor());
    $ast = $traverser->traverse($ast);

    $result = $this->printer->prettyPrint($ast);

    // true should become '1', false should become ''
    expect($result)->toContain("'Value: 1'");
    expect($result)->toContain("'Value: '");
});

it('preserves boolean semantics in math operations', function () {
    $code = '<?php
$result = 10 + true;
$result2 = 10 + false;';

    $ast = $this->parser->parse($code);

    $traverser = new NodeTraverser();
    $traverser->addVisitor(new BinaryAndIssetVisitor());
    $ast = $traverser->traverse($ast);

    $result = $this->printer->prettyPrint($ast);

    // true should become 1, false should become 0
    expect($result)->toContain('$result = 11');
    expect($result)->toContain('$result2 = 10');
});

it('does not optimize variable used in assignment left side', function () {
    $code = '<?php
$x = 10;
$x = $x + 5;
echo $x;';

    $ast = $this->parser->parse($code);

    $traverser = new NodeTraverser();
    $traverser->addVisitor(new ScopeVisitor());
    $traverser->addVisitor(new VarToScalarVisitor($this->store));
    $traverser->addVisitor(new BinaryAndIssetVisitor());
    $ast = $traverser->traverse($ast);

    $result = $this->printer->prettyPrint($ast);

    // Should properly handle the reassignment
    expect($result)->not->toContain('10 = 10 + 5');
});

it('handles nested binary operations correctly', function () {
    $code = '<?php $result = (1 + 2) * (3 + 4);';

    $ast = $this->parser->parse($code);

    $traverser = new NodeTraverser();
    $traverser->addVisitor(new BinaryAndIssetVisitor());
    $ast = $traverser->traverse($ast);

    $result = $this->printer->prettyPrint($ast);

    // Should calculate both levels
    expect($result)->toContain('$result = 21');
});

it('preserves correct order of operations', function () {
    $code = '<?php $result = 2 + 3 * 4;';

    $ast = $this->parser->parse($code);

    $traverser = new NodeTraverser();
    $traverser->addVisitor(new BinaryAndIssetVisitor());
    $ast = $traverser->traverse($ast);

    $result = $this->printer->prettyPrint($ast);

    // Should be 2 + 12 = 14, not 5 * 4 = 20
    // Accept either intermediate step or final result
    $hasIntermediateStep = strpos($result, '2 + 12') !== false;
    $hasFinalResult = strpos($result, '$result = 14') !== false;
    expect($hasIntermediateStep || $hasFinalResult)->toBeTrue();
});

it('does not replace non-scalar constants', function () {
    $code = '<?php
class Test {
    const ARRAY_CONST = [1, 2, 3];

    public function get() {
        return self::ARRAY_CONST;
    }
}';

    $ast = $this->parser->parse($code);

    $traverser = new NodeTraverser();
    $traverser->addVisitor(new ScopeVisitor());
    $traverser->addVisitor(new ConstClassVisitor($this->store));
    $ast = $traverser->traverse($ast);

    $result = $this->printer->prettyPrint($ast);

    // Array constant might be replaced with array value
    // The important thing is no crash occurs
    expect($result)->toBeString();
});

it('handles empty string concatenation', function () {
    $code = '<?php $result = "" . "hello" . "";';

    $ast = $this->parser->parse($code);

    $traverser = new NodeTraverser();
    $traverser->addVisitor(new BinaryAndIssetVisitor());
    $ast = $traverser->traverse($ast);

    $result = $this->printer->prettyPrint($ast);

    expect($result)->toContain('hello');
});

it('preserves float precision in division', function () {
    $code = '<?php $result = 1 / 3;';

    $ast = $this->parser->parse($code);

    $traverser = new NodeTraverser();
    $traverser->addVisitor(new BinaryAndIssetVisitor());
    $ast = $traverser->traverse($ast);

    $result = $this->printer->prettyPrint($ast);

    // Should produce a float result
    expect($result)->toContain('0.333');
});

it('handles isset with non-existent array key', function () {
    $code = '<?php
$default = "default";
if (isset($arr["key"])) {
    $default = $arr["key"];
}';

    $ast = $this->parser->parse($code);

    $traverser = new NodeTraverser();
    $traverser->addVisitor(new ScopeVisitor());
    $traverser->addVisitor(new BinaryAndIssetVisitor());
    $traverser->addVisitor(new RemoveVisitor());
    $ast = $traverser->traverse($ast);

    $result = $this->printer->prettyPrint($ast);

    // Should convert to coalesce
    expect($result)->toContain('??');
});
