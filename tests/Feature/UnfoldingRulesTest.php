<?php

declare(strict_types=1);

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;
use Recombinator\ScopeStore;
use Recombinator\Visitor\BinaryAndIssetVisitor;
use Recombinator\Visitor\ConstClassVisitor;
use Recombinator\Visitor\VarToScalarVisitor;
use Recombinator\Visitor\TernarReturnVisitor;
use Recombinator\Visitor\RemoveVisitor;
use Recombinator\Visitor\ScopeVisitor;

beforeEach(function () {
    $this->parser = (new ParserFactory())->createForHostVersion();
    $this->printer = new StandardPrinter();
    $this->store = new ScopeStore();
});

it('can unfold Auth class example from roadmap', function () {
    $code = '<?php
class Auth {
    const HASH = "test_test";

    public function login($username, $password) {
        if ($username . "_" . $password == self::HASH) {
            return "success";
        }
        return "fail";
    }
}';

    $ast = $this->parser->parse($code);

    $traverser = new NodeTraverser();
    $traverser->addVisitor(new ScopeVisitor());
    $traverser->addVisitor(new ConstClassVisitor($this->store));
    $traverser->addVisitor(new TernarReturnVisitor());
    $traverser->addVisitor(new RemoveVisitor());
    $ast = $traverser->traverse($ast);

    $result = $this->printer->prettyPrint($ast);

    // Constant should be replaced
    expect($result)->toContain('"test_test"');
    expect($result)->not->toContain('const HASH');

    // If-return should be converted to ternary
    expect($result)->toContain('?');
    expect($result)->toContain("'success'");
    expect($result)->toContain("'fail'");
});

it('can optimize binary operations and isset pattern', function () {
    $code = '<?php
$default = "default_value";
if (isset($_GET["value"])) {
    $default = $_GET["value"];
}

$result = 2 + 3 + 5;';

    $ast = $this->parser->parse($code);

    $traverser = new NodeTraverser();
    $traverser->addVisitor(new ScopeVisitor());
    $traverser->addVisitor(new BinaryAndIssetVisitor());
    $traverser->addVisitor(new RemoveVisitor());
    $ast = $traverser->traverse($ast);

    $result = $this->printer->prettyPrint($ast);

    // Binary operations should be calculated
    expect($result)->toContain('$result = 10');

    // isset pattern should be converted to coalesce
    expect($result)->toContain('??');
});

it('can replace variables with scalar values', function () {
    $code = '<?php
$pi = 3.14;
$radius = 5;
$area = $pi * $radius * $radius;
echo $area;';

    $ast = $this->parser->parse($code);

    $traverser = new NodeTraverser();
    $traverser->addVisitor(new ScopeVisitor());
    $traverser->addVisitor(new VarToScalarVisitor($this->store));
    $traverser->addVisitor(new RemoveVisitor());
    $ast = $traverser->traverse($ast);

    $result = $this->printer->prettyPrint($ast);

    // Variables should be replaced with their values
    expect($result)->toContain('3.14');
    expect($result)->toContain('5');
    expect($result)->not->toContain('$pi = 3.14');
    expect($result)->not->toContain('$radius = 5');
});

it('can combine multiple optimizations', function () {
    $code = '<?php
class Config {
    const API_VERSION = 2;
    const API_BASE = 1;
}

$version = Config::API_VERSION + Config::API_BASE;
echo "API v" . $version;';

    $ast = $this->parser->parse($code);

    $traverser = new NodeTraverser();
    $traverser->addVisitor(new ScopeVisitor());
    $traverser->addVisitor(new ConstClassVisitor($this->store));
    $traverser->addVisitor(new BinaryAndIssetVisitor());
    $traverser->addVisitor(new VarToScalarVisitor($this->store));
    $traverser->addVisitor(new RemoveVisitor());
    $ast = $traverser->traverse($ast);

    $result = $this->printer->prettyPrint($ast);

    // Constants replaced and added
    expect($result)->toContain('$version = 3');

    // String concatenation with scalar
    expect($result)->toContain("'API v'");
});

it('can optimize complex isset patterns', function () {
    $code = '<?php
$username = "default_username";
if (isset($_GET["username"])) {
    $username = $_GET["username"];
}

$password = "default_password";
if (isset($_GET["password"])) {
    $password = $_GET["password"];
}';

    $ast = $this->parser->parse($code);

    $traverser = new NodeTraverser();
    $traverser->addVisitor(new ScopeVisitor());
    $traverser->addVisitor(new BinaryAndIssetVisitor());
    $traverser->addVisitor(new RemoveVisitor());
    $ast = $traverser->traverse($ast);

    $result = $this->printer->prettyPrint($ast);

    // Both isset patterns should be converted
    expect($result)->toContain('$username = $_GET[\'username\'] ?? $username');
    expect($result)->toContain('$password = $_GET[\'password\'] ?? $password');
});

it('can optimize mathematical expressions', function () {
    $code = '<?php
$a = 10;
$b = 20;
$c = 30;

$sum = $a + $b + $c;
$product = $a * $b;
$division = $b / $a;';

    $ast = $this->parser->parse($code);

    $traverser = new NodeTraverser();
    $traverser->addVisitor(new ScopeVisitor());
    $traverser->addVisitor(new VarToScalarVisitor($this->store));
    $traverser->addVisitor(new BinaryAndIssetVisitor());
    $traverser->addVisitor(new RemoveVisitor());
    $ast = $traverser->traverse($ast);

    $result = $this->printer->prettyPrint($ast);

    // All mathematical operations should be calculated
    expect($result)->toContain('$sum = 60');
    expect($result)->toContain('$product = 200');
    expect($result)->toContain('$division = 2');
});

it('can optimize string concatenation chain', function () {
    $code = '<?php
$hello = "Hello";
$world = "World";
$message = $hello . " " . $world . "!";';

    $ast = $this->parser->parse($code);

    $traverser = new NodeTraverser();
    $traverser->addVisitor(new ScopeVisitor());
    $traverser->addVisitor(new VarToScalarVisitor($this->store));
    $traverser->addVisitor(new BinaryAndIssetVisitor());
    $traverser->addVisitor(new RemoveVisitor());
    $ast = $traverser->traverse($ast);

    $result = $this->printer->prettyPrint($ast);

    // String concatenations should be simplified
    expect($result)->toContain('Hello');
    expect($result)->toContain('World');
});

it('preserves side effects and does not optimize non-pure code', function () {
    $code = '<?php
$arr = [1, 2, 3];
$x = array_sum($arr);
echo $x;';

    $ast = $this->parser->parse($code);

    $traverser = new NodeTraverser();
    $traverser->addVisitor(new ScopeVisitor());
    $traverser->addVisitor(new VarToScalarVisitor($this->store));
    $ast = $traverser->traverse($ast);

    $result = $this->printer->prettyPrint($ast);

    // Array and function call should remain
    expect($result)->toContain('$arr = [1, 2, 3]');
    expect($result)->toContain('array_sum($arr)');
});
