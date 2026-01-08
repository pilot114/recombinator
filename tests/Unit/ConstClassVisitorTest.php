<?php

declare(strict_types=1);

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;
use Recombinator\Domain\ScopeStore;
use Recombinator\Transformation\Visitor\ConstClassVisitor;
use Recombinator\Transformation\Visitor\RemoveVisitor;
use Recombinator\Transformation\Visitor\ScopeVisitor;

beforeEach(function () {
    $this->parser = (new ParserFactory())->createForHostVersion();
    $this->printer = new StandardPrinter();
    $this->store = new ScopeStore();
    $this->visitor = new ConstClassVisitor($this->store);
});

it('can replace class constant with scalar value inside class', function () {
    $code = '<?php
class TestClass {
    const MY_CONST = 42;

    public function test() {
        return self::MY_CONST;
    }
}';

    $ast = $this->parser->parse($code);
    $traverser = new NodeTraverser();
    $traverser->addVisitor(new ScopeVisitor());
    $traverser->addVisitor($this->visitor);
    $traverser->addVisitor(new RemoveVisitor());
    $ast = $traverser->traverse($ast);

    $result = $this->printer->prettyPrint($ast);

    expect($result)->toContain('return 42');
    expect($result)->not->toContain('const MY_CONST');
});

it('can replace class constant with string value', function () {
    $code = '<?php
class Auth {
    const HASH = "test_hash";

    public function check($pass) {
        return $pass === self::HASH;
    }
}';

    $ast = $this->parser->parse($code);
    $traverser = new NodeTraverser();
    $traverser->addVisitor(new ScopeVisitor());
    $traverser->addVisitor($this->visitor);
    $traverser->addVisitor(new RemoveVisitor());
    $ast = $traverser->traverse($ast);

    $result = $this->printer->prettyPrint($ast);

    // Accept both single and double quotes (php-parser may use either)
    expect($result)->toMatch('/["\']test_hash["\']/');
    expect($result)->not->toContain('const HASH');
});

it('can replace class constant accessed from outside class', function () {
    $code = '<?php
class Config {
    const API_KEY = "secret_key";
}

$key = Config::API_KEY;';

    $ast = $this->parser->parse($code);
    $traverser = new NodeTraverser();
    $traverser->addVisitor(new ScopeVisitor());
    $traverser->addVisitor($this->visitor);
    $traverser->addVisitor(new RemoveVisitor());
    $ast = $traverser->traverse($ast);

    $result = $this->printer->prettyPrint($ast);

    // Accept both single and double quotes (php-parser may use either)
    expect($result)->toMatch('/\$key = ["\']secret_key["\']/');

});

it('can handle multiple constants in one class', function () {
    $code = '<?php
class Constants {
    const FOO = 1;
    const BAR = 2;
    const BAZ = 3;

    public function sum() {
        return self::FOO + self::BAR + self::BAZ;
    }
}';

    $ast = $this->parser->parse($code);
    $traverser = new NodeTraverser();
    $traverser->addVisitor(new ScopeVisitor());
    $traverser->addVisitor($this->visitor);
    $traverser->addVisitor(new RemoveVisitor());
    $ast = $traverser->traverse($ast);

    $result = $this->printer->prettyPrint($ast);

    expect($result)->toContain('return 1 + 2 + 3');
    expect($result)->not->toContain('const FOO');
    expect($result)->not->toContain('const BAR');
    expect($result)->not->toContain('const BAZ');
});

it('stores constant in global scope', function () {
    $code = '<?php
class TestClass {
    const MY_CONST = 100;
}';

    $ast = $this->parser->parse($code);
    $traverser = new NodeTraverser();
    $traverser->addVisitor(new ScopeVisitor());
    $traverser->addVisitor($this->visitor);
    $ast = $traverser->traverse($ast);

    $constant = $this->store->getConstFromGlobal('MY_CONST');

    expect($constant)->not->toBeNull()
        ->and($constant->value)->toBe(100);
});

it('can handle boolean constants', function () {
    $code = '<?php
class Flags {
    const DEBUG = true;
    const PRODUCTION = false;

    public function isDebug() {
        return self::DEBUG;
    }
}';

    $ast = $this->parser->parse($code);
    $traverser = new NodeTraverser();
    $traverser->addVisitor(new ScopeVisitor());
    $traverser->addVisitor($this->visitor);
    $traverser->addVisitor(new RemoveVisitor());
    $ast = $traverser->traverse($ast);

    $result = $this->printer->prettyPrint($ast);

    expect($result)->toContain('return true');
});

it('can handle float constants', function () {
    $code = '<?php
class Math {
    const PI = 3.14159;

    public function circleArea($r) {
        return self::PI * $r * $r;
    }
}';

    $ast = $this->parser->parse($code);
    $traverser = new NodeTraverser();
    $traverser->addVisitor(new ScopeVisitor());
    $traverser->addVisitor($this->visitor);
    $traverser->addVisitor(new RemoveVisitor());
    $ast = $traverser->traverse($ast);

    $result = $this->printer->prettyPrint($ast);

    expect($result)->toContain('3.14159');
    expect($result)->not->toContain('const PI');
});
