<?php

declare(strict_types=1);

namespace Recombinator\Contract;

/**
 * Interface for symbol table (scope store)
 */
interface SymbolTableInterface
{
    /**
     * Set variable in current scope
     *
     * @param string $name
     * @param mixed $value
     * @return void
     */
    public function setVarToScope(string $name, mixed $value): void;

    /**
     * Get variable from current scope
     *
     * @param string $name
     * @return mixed
     */
    public function getVarFromScope(string $name): mixed;

    /**
     * Set constant to global scope
     *
     * @param string $name
     * @param mixed $value
     * @return void
     */
    public function setConstToGlobal(string $name, mixed $value): void;

    /**
     * Get constant from global scope
     *
     * @param string $name
     * @return mixed
     */
    public function getConstFromGlobal(string $name): mixed;

    /**
     * Set current scope
     *
     * @param string|null $scopeName
     * @return void
     */
    public function setCurrentScope(?string $scopeName): void;

    /**
     * Get current scope name
     *
     * @return string|null
     */
    public function getCurrentScope(): ?string;
}
