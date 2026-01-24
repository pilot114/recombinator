<?php

declare(strict_types=1);

use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;
use PhpParser\Node;
use Recombinator\Domain\ScopeStore;
use Recombinator\Transformation\Visitor\PropertyAccessVisitor;
use Recombinator\Transformation\Visitor\ScopeVisitor;

beforeEach(
    function (): void {
        $this->parser = new ParserFactory()->createForHostVersion();
        $this->printer = new StandardPrinter();
        $this->store = new ScopeStore();
        $this->visitor = new PropertyAccessVisitor($this->store);
    }
);

it(
    'can replace property access with variable',
    function (): void {
        $code = '<?php
$obj = new TestClass();
echo $obj->name;';

        // Manually set up instance data
        $this->store->setInstanceToScope(
            'obj',
            'TestClass',
            [
            'properties' => [
            'name' => 'var_name_123'
            ]
            ]
        );

        $ast = $this->parser->parse($code);
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ScopeVisitor());
        $traverser->addVisitor($this->visitor);

        $ast = $traverser->traverse($ast);

        $result = $this->printer->prettyPrint($ast);

        expect($result)->toContain('$var_name_123');
    }
);

it(
    'should not replace property if instance not found',
    function (): void {
        $code = '<?php echo $obj->name;';

        $ast = $this->parser->parse($code);
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ScopeVisitor());
        $traverser->addVisitor($this->visitor);

        $ast = $traverser->traverse($ast);

        $result = $this->printer->prettyPrint($ast);

        expect($result)->toContain('$obj->name');
    }
);

it(
    'should not replace property if property not in instance',
    function (): void {
        $code = '<?php echo $obj->unknownProperty;';

        // Set up instance but without the property
        $this->store->setInstanceToScope(
            'obj',
            'TestClass',
            [
            'properties' => [
            'name' => 'var_name'
            ]
            ]
        );

        $ast = $this->parser->parse($code);
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ScopeVisitor());
        $traverser->addVisitor($this->visitor);

        $ast = $traverser->traverse($ast);

        $result = $this->printer->prettyPrint($ast);

        expect($result)->toContain('$obj->unknownProperty');
    }
);

it(
    'can handle multiple property accesses',
    function (): void {
        $code = '<?php
$user = new User();
echo $user->name . " " . $user->age;';

        $this->store->setInstanceToScope(
            'user',
            'User',
            [
            'properties' => [
            'name' => 'var_name',
            'age' => 'var_age'
            ]
            ]
        );

        $ast = $this->parser->parse($code);
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ScopeVisitor());
        $traverser->addVisitor($this->visitor);

        $ast = $traverser->traverse($ast);

        $result = $this->printer->prettyPrint($ast);

        expect($result)->toContain('$var_name');
        expect($result)->toContain('$var_age');
    }
);

it(
    'should preserve this property access for later processing',
    function (): void {
        $code = '<?php
class Test {
    public function getValue() {
        return $this->value;
    }
}';

        $ast = $this->parser->parse($code);
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ScopeVisitor());
        $traverser->addVisitor($this->visitor);

        $ast = $traverser->traverse($ast);

        $result = $this->printer->prettyPrint($ast);

        // $this->property should remain unchanged for now
        expect($result)->toContain('$this->value');
    }
);

it(
    'can find class name and instance from store',
    function (): void {
        $this->store->setInstanceToScope(
            'myObj',
            'MyClass',
            [
            'properties' => ['prop' => 'var_prop']
            ]
        );

        $result = $this->store->findClassNameAndInstance('myObj');

        expect($result)->not->toBeNull()
            ->and($result[0])->toBe('MyClass')
            ->and($result[1]['properties'])->toHaveKey('prop');
    }
);

it(
    'returns null when instance not found in store',
    function (): void {
        $result = $this->store->findClassNameAndInstance('nonExistent');

        expect($result)->toBeNull();
    }
);

it(
    'can replace nested property access',
    function (): void {
        $code = '<?php
$calc = new Calculator();
$result = $calc->value + 10;';

        $this->store->setInstanceToScope(
            'calc',
            'Calculator',
            [
            'properties' => [
            'value' => 'var_value'
            ]
            ]
        );

        $ast = $this->parser->parse($code);
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new ScopeVisitor());
        $traverser->addVisitor($this->visitor);

        $ast = $traverser->traverse($ast);

        $result = $this->printer->prettyPrint($ast);

        expect($result)->toContain('$result = $var_value + 10');
    }
);
