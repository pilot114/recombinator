<?php

declare(strict_types=1);

use Recombinator\Contract\SymbolTableInterface;
use Recombinator\Domain\ScopeStore;

beforeEach(
    function (): void {
        $this->store = new ScopeStore();
        $this->store->setCurrentScope('test_scope');
    }
);

it(
    'implements SymbolTableInterface',
    function (): void {
        expect(new ScopeStore())->toBeInstanceOf(SymbolTableInterface::class);
    }
);

it(
    'can set and get variable from scope',
    function (): void {
        $this->store->setVarToScope('testVar', 'testValue');

        expect($this->store->getVarFromScope('testVar'))->toBe('testValue');
    }
);

it(
    'returns null for non-existent variable',
    function (): void {
        expect($this->store->getVarFromScope('nonExistent'))->toBeNull();
    }
);

it(
    'can remove variable from scope',
    function (): void {
        $this->store->setVarToScope('testVar', 'testValue');
        $this->store->removeVarFromScope('testVar');

        expect($this->store->getVarFromScope('testVar'))->toBeNull();
    }
);

it(
    'can set and get constant from scope',
    function (): void {
        $this->store->setConstToScope('TEST_CONST', 'constValue');

        expect($this->store->getConstFromScope('TEST_CONST'))->toBe('constValue');
    }
);

it(
    'can set and get global constant',
    function (): void {
        $this->store->setConstToGlobal('GLOBAL_CONST', 'globalValue');

        expect($this->store->getConstFromGlobal('GLOBAL_CONST'))->toBe('globalValue');
    }
);

it(
    'can set and get global function',
    function (): void {
        $this->store->setFunctionToGlobal('testFunction', ['body' => 'test']);

        expect($this->store->getFunctionFromGlobal('testFunction'))->toBe(['body' => 'test']);
    }
);

it(
    'can set and get global class',
    function (): void {
        $this->store->setClassToGlobal('TestClass', ['methods' => []]);

        expect($this->store->getClassFromGlobal('TestClass'))->toBe(['methods' => []]);
    }
);

it(
    'can find class name and instance',
    function (): void {
        $this->store->setClassToGlobal(
            'TestClass',
            [
            'instances' => [
            ['name' => 'instance1', 'data' => 'test']
            ]
            ]
        );

        $result = $this->store->findClassNameAndInstance('instance1');

        expect($result)->toBeArray()
            ->and($result[0])->toBe('TestClass')
            ->and($result[1])->toBe(['name' => 'instance1', 'data' => 'test']);
    }
);

it(
    'returns null when class instance not found',
    function (): void {
        expect($this->store->findClassNameAndInstance('nonExistent'))->toBeNull();
    }
);

it(
    'handles multiple independent scopes',
    function (): void {
        $this->store->setCurrentScope('scope_a');
        $this->store->setVarToScope('x', 'a_value');

        $this->store->setCurrentScope('scope_b');
        $this->store->setVarToScope('x', 'b_value');

        $this->store->setCurrentScope('scope_a');

        expect($this->store->getVarFromScope('x'))->toBe('a_value');

        $this->store->setCurrentScope('scope_b');
        expect($this->store->getVarFromScope('x'))->toBe('b_value');
    }
);

it(
    'handles null scope as default',
    function (): void {
        $store = new ScopeStore();
        $store->setCurrentScope(null);
        $store->setVarToScope('x', 'default_value');

        expect($store->getVarFromScope('x'))->toBe('default_value');
    }
);

it(
    'returns all global constants correctly',
    function (): void {
        $this->store->setConstToGlobal('A', 1);
        $this->store->setConstToGlobal('B', 2);

        $consts = $this->store->getAllGlobalConsts();

        expect($consts)->toBe(['A' => 1, 'B' => 2]);
    }
);

it(
    'stores and retrieves instances by class name',
    function (): void {
        $this->store->setInstanceToScope('obj1', 'MyClass', ['data' => 'test']);

        $result = $this->store->findClassNameAndInstance('obj1');

        expect($result)->not->toBeNull()
            ->and($result[0])->toBe('MyClass')
            ->and($result[1]['name'])->toBe('obj1');
    }
);
