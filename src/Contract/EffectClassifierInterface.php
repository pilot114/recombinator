<?php

declare(strict_types=1);

namespace Recombinator\Contract;

use PhpParser\Node;
use Recombinator\Domain\SideEffectType;

/**
 * Интерфейс для классификации побочных эффектов
 *
 * Определяет контракт для анализа и классификации узлов AST
 * по типам побочных эффектов (PURE, IO, DATABASE и т.д.).
 * Используется для разделения кода на группы по типу эффектов.
 */
interface EffectClassifierInterface
{
    /**
     * Классифицирует узел по типу побочного эффекта
     *
     * @param  Node $node Узел для анализа
     * @return SideEffectType Тип побочного эффекта
     */
    public function classify(Node $node): SideEffectType;

    /**
     * Проверяет, является ли функция чистой
     *
     * @param  string $functionName Имя функции
     * @return boolean True, если функция не имеет побочных эффектов
     */
    public function isPureFunction(string $functionName): bool;
}
