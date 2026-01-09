<?php

declare(strict_types=1);

use Recombinator\Domain\ScopeStore;

beforeEach(
    function (): void {
        $this->store = new ScopeStore();
        $this->store->setCurrentScope('test_scope');
    }
);

it(
    'can set and get variable from scope', function (): void {
        $this->store->setVarToScope('testVar', 'testValue');

        expect($this->store->getVarFromScope('testVar'))->toBe('testValue');
    }
);

it(
    'returns null for non-existent variable', function (): void {
        expect($this->store->getVarFromScope('nonExistent'))->toBeNull();
    }
);

it(
    'can remove variable from scope', function (): void {
        $this->store->setVarToScope('testVar', 'testValue');
        $this->store->removeVarFromScope('testVar');

        expect($this->store->getVarFromScope('testVar'))->toBeNull();
    }
);

it(
    'can set and get constant from scope', function (): void {
        $this->store->setConstToScope('TEST_CONST', 'constValue');

        expect($this->store->getConstFromScope('TEST_CONST'))->toBe('constValue');
    }
);

it(
    'can set and get global constant', function (): void {
        $this->store->setConstToGlobal('GLOBAL_CONST', 'globalValue');

        expect($this->store->getConstFromGlobal('GLOBAL_CONST'))->toBe('globalValue');
    }
);

it(
    'can set and get global function', function (): void {
        $this->store->setFunctionToGlobal('testFunction', ['body' => 'test']);

        expect($this->store->getFunctionFromGlobal('testFunction'))->toBe(['body' => 'test']);
    }
);

it(
    'can set and get global class', function (): void {
        $this->store->setClassToGlobal('TestClass', ['methods' => []]);

        expect($this->store->getClassFromGlobal('TestClass'))->toBe(['methods' => []]);
    }
);

it(
    'can find class name and instance', function (): void {
        $this->store->setClassToGlobal(
            'TestClass', [
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
    'returns null when class instance not found', function (): void {
        expect($this->store->findClassNameAndInstance('nonExistent'))->toBeNull();
    }
);
