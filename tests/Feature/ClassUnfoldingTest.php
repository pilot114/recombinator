<?php

declare(strict_types=1);

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;
use Recombinator\Domain\ScopeStore;
use Recombinator\Transformation\Visitor\ConstructorAndMethodsVisitor;
use Recombinator\Transformation\Visitor\PropertyAccessVisitor;

beforeEach(
    function (): void {
        $this->parser = new ParserFactory()->createForHostVersion();
        $this->printer = new StandardPrinter();
        $this->store = new ScopeStore();
    }
);

it(
    'can unfold simple class instantiation and method call',
    function (): void {
        $code = '<?php
class Calculator {
    public $base = 10;

    public function add($x) {
        return $this->base + $x;
    }
}

$calc = new Calculator();
$result = $calc->add(5);
';

        $ast = $this->parser->parse($code);

        // Первый проход - сбор информации о классах
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ConstructorAndMethodsVisitor($this->store));

        $ast = $traverser->traverse($ast);

        $class = $this->store->getClassFromGlobal('Calculator');

        expect($class)->toBeArray()
            ->and($class['props'])->toHaveKey('base')
            ->and($class['methods'])->toHaveKey('add');
    }
);

it(
    'can unfold class with constructor',
    function (): void {
        $code = '<?php
class Person {
    public $name = "";

    public function __construct($personName) {
        $this->name = $personName;
    }

    public function greet() {
        return "Hello, " . $this->name;
    }
}
';

        $ast = $this->parser->parse($code);

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ConstructorAndMethodsVisitor($this->store));

        $ast = $traverser->traverse($ast);

        $class = $this->store->getClassFromGlobal('Person');

        expect($class)->toBeArray()
            ->and($class['methods'])->toHaveKey('__construct')
            ->and($class['methods'])->toHaveKey('greet');
    }
);

it(
    'can unfold method chain calls',
    function (): void {
        $code = '<?php
class Builder {
    public $value = 0;

    public function add($x) {
        return $this->value + $x;
    }

    public function multiply($x) {
        return $this->value * $x;
    }
}
';

        $ast = $this->parser->parse($code);

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ConstructorAndMethodsVisitor($this->store));

        $ast = $traverser->traverse($ast);

        $class = $this->store->getClassFromGlobal('Builder');

        expect($class)->toBeArray()
            ->and($class['methods'])->toHaveKey('add')
            ->and($class['methods'])->toHaveKey('multiply');
    }
);

it(
    'can handle class inheritance',
    function (): void {
        $code = '<?php
class Animal {
    public function sound() {
        return "Some sound";
    }
}

class Dog extends Animal {
    public function bark() {
        return "Woof!";
    }
}
';

        $ast = $this->parser->parse($code);

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ConstructorAndMethodsVisitor($this->store));

        $ast = $traverser->traverse($ast);

        $animal = $this->store->getClassFromGlobal('Animal');
        $dog = $this->store->getClassFromGlobal('Dog');

        expect($animal)->toBeArray()
            ->and($animal['methods'])->toHaveKey('sound')
            ->and($dog)->toBeArray()
            ->and($dog['parent'])->toBe('Animal')
            ->and($dog['methods'])->toHaveKey('bark');
    }
);
