<?php

declare(strict_types=1);

namespace Recombinator\Domain;

/**
 * Хранилище областей видимости (scopes) для обмена данными между visitor'ами
 *
 * Управляет переменными, константами, функциями и классами в разных областях
 * видимости при трансформации и выполнении кода. Реализует иерархическую
 * модель scopes: глобальная область -> функции/методы -> локальные области.
 *
 * TODO: нужно выделить скопы в соответствии с php скопами
 * global
 *   function1
 *   function2
 *   namespace
 *     classA (static)
 *     objectA (instance)
 *       methodAA
 *       methodAB
 *  + anonFunctions
 */
class ScopeStore
{
    /**
     * @var array{vars?: array<string, mixed>, consts?: array<string, mixed>, functions?: array<string, mixed>, classes?: array<string, mixed>}
     */
    private array $global = [];

    /**
     * @var array<string, array{vars?: array<string, mixed>, consts?: array<string, mixed>}>
     */
    private array $scopes = [];

    private ?string $currentScope = null;

    public function setVarToScope(string $name, mixed $value): void
    {
        $scope = $this->currentScope ?? '';
        if (!isset($this->scopes[$scope])) {
            $this->scopes[$scope] = [
                'vars' => []
            ];
        }

        if (!isset($this->scopes[$scope]['vars'])) {
            $this->scopes[$scope]['vars'] = [];
        }

        $this->scopes[$scope]['vars'][$name] = $value;
    }

    public function getVarFromScope(string $name): mixed
    {
        $scope = $this->currentScope ?? '';
        if (!isset($this->scopes[$scope]['vars'])) {
            return null;
        }

        return $this->scopes[$scope]['vars'][$name] ?? null;
    }

    public function removeVarFromScope(string $name): void
    {
        $scope = $this->currentScope ?? '';
        if (isset($this->scopes[$scope]['vars'])) {
            unset($this->scopes[$scope]['vars'][$name]);
        }
    }

    public function setConstToScope(string $name, mixed $value): void
    {
        $scope = $this->currentScope ?? '';
        if (!isset($this->scopes[$scope])) {
            $this->scopes[$scope] = [
                'consts' => []
            ];
        }

        if (!isset($this->scopes[$scope]['consts'])) {
            $this->scopes[$scope]['consts'] = [];
        }

        $this->scopes[$scope]['consts'][$name] = $value;
    }

    public function getConstFromScope(string $name): mixed
    {
        $scope = $this->currentScope ?? '';
        if (!isset($this->scopes[$scope]['consts'])) {
            return null;
        }

        return $this->scopes[$scope]['consts'][$name] ?? null;
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
        if (!isset($this->global['consts'])) {
            return null;
        }

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
        if (!isset($this->global['functions'])) {
            return null;
        }

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
        if (!isset($this->global['classes'])) {
            return null;
        }

        return $this->global['classes'][$name] ?? null;
    }

    /**
     * @param array<string, mixed> $instanceData
     */
    public function setInstanceToScope(string $instanceName, string $className, array $instanceData): void
    {
        if (!isset($this->global['classes'])) {
            $this->global['classes'] = [];
        }

        if (!isset($this->global['classes'][$className])) {
            $this->global['classes'][$className] = [];
        }

        $classData = $this->global['classes'][$className];
        if (!is_array($classData)) {
            $classData = [];
        }

        if (!isset($classData['instances'])) {
            $classData['instances'] = [];
        }

        $instances = $classData['instances'];
        if (!is_array($instances)) {
            $instances = [];
        }

        $instanceData['name'] = $instanceName;
        $instances[] = $instanceData;
        $classData['instances'] = $instances;
        $this->global['classes'][$className] = $classData;
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    public function findClassNameAndInstance(string $instanceName): ?array
    {
        if (!isset($this->global['classes'])) {
            return null;
        }

        foreach ($this->global['classes'] as $className => $class) {
            if (!is_string($className) || !is_array($class) || !isset($class['instances'])) {
                continue;
            }

            $instances = $class['instances'];
            if (!is_array($instances)) {
                continue;
            }

            foreach ($instances as $instance) {
                if (!is_array($instance) || !isset($instance['name'])) {
                    continue;
                }

                if ($instance['name'] === $instanceName) {
                    /** @var array<string, mixed> $instance */
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
     *
     * @return array<string, mixed>
     */
    public function getAllGlobalConsts(): array
    {
        if (!isset($this->global['consts']) || !is_array($this->global['consts'])) {
            return [];
        }

        /** @var array<string, mixed> $consts */
        $consts = $this->global['consts'];
        return $consts;
    }

    /**
     * Get all scopes
     *
     * @return array<string, array<string, mixed>>
     */
    public function getAllScopes(): array
    {
        return $this->scopes;
    }

    /**
     * Get all global data
     *
     * @return array<string, mixed>
     */
    public function getGlobal(): array
    {
        return $this->global;
    }
}