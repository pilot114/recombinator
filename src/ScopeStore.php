<?php

declare(strict_types=1);

namespace Recombinator;

/**
 * Класс для обмена данными между разными скопами.
 * Желательно в рамках одного Visitor - чтобы не усложнять логику
 *
 * TODO: нужно выделить скопы в соотевствии с php скопами
 * global
 *   function1
 *   function2
 *   namespace
 *     classA (static)
 *     objectA (instance)
 *       methodAA
 *       methodAB
 *
 *  + anonFunctions
 */
class ScopeStore
{
    /** @var array<string, mixed> */
    private array $global = [];

    /** @var array<string, mixed> */
    private array $scopes = [];

    private ?string $currentScope = null;

    public function setVarToScope(string $name, mixed $value): void
    {
        if (!isset($this->scopes[$this->currentScope])) {
            $this->scopes[$this->currentScope] = [
                'vars' => []
            ];
        }
        $this->scopes[$this->currentScope]['vars'][$name] = $value;
    }

    public function getVarFromScope(string $name): mixed
    {
        return $this->scopes[$this->currentScope]['vars'][$name] ?? null;
    }

    public function removeVarFromScope(string $name): void
    {
        unset($this->scopes[$this->currentScope]['vars'][$name]);
    }

    public function setConstToScope(string $name, mixed $value): void
    {
        if (!isset($this->scopes[$this->currentScope])) {
            $this->scopes[$this->currentScope] = [
                'consts' => []
            ];
        }
        $this->scopes[$this->currentScope]['consts'][$name] = $value;
    }

    public function getConstFromScope(string $name): mixed
    {
        return $this->scopes[$this->currentScope]['consts'][$name] ?? null;
    }

    public function setConstToGlobal(string $name, mixed $value): void
    {
        if (!isset($this->global['consts'])) {
            $this->global['consts'] = [];
        }
        $this->global['consts'][$name] = $value;
    }

    public function getConstFromGlobal(string $name): mixed
    {
        return $this->global['consts'][$name] ?? null;
    }

    public function setFunctionToGlobal(string $name, mixed $value): void
    {
        if (!isset($this->global['functions'])) {
            $this->global['functions'] = [];
        }
        $this->global['functions'][$name] = $value;
    }

    public function getFunctionFromGlobal(string $name): mixed
    {
        return $this->global['functions'][$name] ?? null;
    }

    public function setClassToGlobal(string $name, mixed $value): void
    {
        if (!isset($this->global['classes'])) {
            $this->global['classes'] = [];
        }
        $this->global['classes'][$name] = $value;
    }

    public function getClassFromGlobal(string $name): mixed
    {
        return $this->global['classes'][$name] ?? null;
    }

    /**
     * @return array{string, array<string, mixed>>|null
     */
    public function findClassNameAndInstance(string $instanceName): ?array
    {
        if (!isset($this->global['classes'])) {
            return null;
        }

        foreach ($this->global['classes'] as $className => $class) {
            if (!isset($class['instances'])) {
                continue;
            }
            foreach ($class['instances'] as $instance) {
                if ($instance['name'] === $instanceName) {
                    return [$className, $instance];
                }
            }
        }

        return null;
    }

    public function setCurrentScope(?string $scopeName): void
    {
        $this->currentScope = $scopeName;
    }

    public function getCurrentScope(): ?string
    {
        return $this->currentScope;
    }

    /**
     * Get all global constants
     * @return array<string, mixed>
     */
    public function getAllGlobalConsts(): array
    {
        return $this->global['consts'] ?? [];
    }

    /**
     * Get all scopes
     * @return array<string, mixed>
     */
    public function getAllScopes(): array
    {
        return $this->scopes;
    }

    /**
     * Get all global data
     * @return array<string, mixed>
     */
    public function getGlobal(): array
    {
        return $this->global;
    }
}