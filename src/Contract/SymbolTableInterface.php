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
     */
    public function setVarToScope(string $name, mixed $value): void;

    /**
     * Get variable from current scope
     */
    public function getVarFromScope(string $name): mixed;

    /**
     * Set constant to global scope
     */
    public function setConstToGlobal(string $name, mixed $value): void;

    /**
     * Get constant from global scope
     */
    public function getConstFromGlobal(string $name): mixed;

    /**
     * Set current scope
     */
    public function setCurrentScope(?string $scopeName): void;

    /**
     * Get current scope name
     */
    public function getCurrentScope(): ?string;
}
