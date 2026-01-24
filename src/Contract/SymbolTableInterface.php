<?php

declare(strict_types=1);

namespace Recombinator\Contract;

/**
 * Интерфейс для таблицы символов (хранилища областей видимости)
 *
 * Определяет контракт для управления переменными и константами в различных
 * областях видимости (scopes). Используется при выполнении и трансформации
 * кода для отслеживания значений переменных и констант в разных контекстах.
 */
interface SymbolTableInterface
{
    /**
     * Устанавливает значение переменной в текущей области видимости
     */
    public function setVarToScope(string $name, mixed $value): void;

    /**
     * Получает значение переменной из текущей области видимости
     */
    public function getVarFromScope(string $name): mixed;

    /**
     * Устанавливает константу в глобальную область видимости
     */
    public function setConstToGlobal(string $name, mixed $value): void;

    /**
     * Получает константу из глобальной области видимости
     */
    public function getConstFromGlobal(string $name): mixed;

    /**
     * Устанавливает текущую область видимости
     */
    public function setCurrentScope(?string $scopeName): void;

    /**
     * Получает имя текущей области видимости
     */
    public function getCurrentScope(): ?string;
}
