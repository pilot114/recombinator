<?php

declare(strict_types=1);

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use Recombinator\Domain\ScopeStore;
use Recombinator\Transformation\Visitor\ConstructorAndMethodsVisitor;

beforeEach(
    function (): void {
        $this->parser = new ParserFactory()->createForHostVersion();
        $this->store = new ScopeStore();
        $this->visitor = new ConstructorAndMethodsVisitor($this->store);
    }
);

it(
    'can process simple class with properties',
    function (): void {
        $code = '<?php
class TestClass {
    public $name = "test";
    public $value = 10;

    public function getName() {
        return $this->name;
    }
}';

        $ast = $this->parser->parse($code);
        $traverser = new NodeTraverser();
        $traverser->addVisitor($this->visitor);
        $traverser->traverse($ast);

        $class = $this->store->getClassFromGlobal('TestClass');

        expect($class)->toBeArray()
            ->and($class['props'])->toHaveKey('name')
            ->and($class['props'])->toHaveKey('value')
            ->and($class['methods'])->toHaveKey('getName');
    }
);

it(
    'can process class with constructor',
    function (): void {
        $code = '<?php
class TestClass {
    public $value = 0;

    public function __construct($val) {
        $this->value = $val;
    }

    public function getValue() {
        return $this->value;
    }
}';

        $ast = $this->parser->parse($code);
        $traverser = new NodeTraverser();
        $traverser->addVisitor($this->visitor);
        $traverser->traverse($ast);

        $class = $this->store->getClassFromGlobal('TestClass');

        expect($class)->toBeArray()
            ->and($class['methods'])->toHaveKey('__construct')
            ->and($class['methods'])->toHaveKey('getValue');
    }
);

it(
    'can process class with inheritance',
    function (): void {
        $code = '<?php
class ParentClass {
    public function parentMethod() {
        return "parent";
    }
}

class ChildClass extends ParentClass {
    public function childMethod() {
        return "child";
    }
}';

        $ast = $this->parser->parse($code);
        $traverser = new NodeTraverser();
        $traverser->addVisitor($this->visitor);
        $traverser->traverse($ast);

        $parentClass = $this->store->getClassFromGlobal('ParentClass');
        $childClass = $this->store->getClassFromGlobal('ChildClass');

        expect($parentClass)->toBeArray()
            ->and($parentClass['methods'])->toHaveKey('parentMethod')
            ->and($childClass)->toBeArray()
            ->and($childClass['parent'])->toBe('ParentClass')
            ->and($childClass['methods'])->toHaveKey('childMethod');
    }
);

it(
    'should not optimize class with complex methods',
    function (): void {
        $code = '<?php
class ComplexClass {
    public function complexMethod($x) {
        if ($x > 0) {
            return $x * 2;
        }
        return 0;
    }
}';

        $ast = $this->parser->parse($code);
        $traverser = new NodeTraverser();
        $traverser->addVisitor($this->visitor);
        $traverser->traverse($ast);

        $class = $this->store->getClassFromGlobal('ComplexClass');

        expect($class)->toBeNull();
    }
);
