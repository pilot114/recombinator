<?php

declare(strict_types=1);

namespace Recombinator\Transformation;

use PhpParser\Node;
use Recombinator\Domain\SeparationResult;

/**
 * Оптимизатор размещения объявлений переменных
 *
 * Реализует правило FOLD-GROUP-3: Группировка инициализации переменных
 *
 * Оптимизирует размещение объявлений переменных:
 * - Группирует инициализацию связанных переменных
 * - Перемещает объявления ближе к месту использования
 * - Объединяет повторяющиеся паттерны инициализации
 *
 * Пример:
 * ```php
 * // До оптимизации
 * $x = $point['x'];
 * echo "Processing...";
 * $y = $point['y'];
 * echo "Calculating...";
 * $z = $point['z'];
 *
 * // После оптимизации
 * // Coordinates
 * $x = $point['x'];
 * $y = $point['y'];
 * $z = $point['z'];
 *
 * // Output
 * echo "Processing...";
 * echo "Calculating...";
 * ```
 */
class VariableDeclarationOptimizer
{
    private SideEffectSeparator $separator;

    public function __construct(?SideEffectSeparator $separator = null)
    {
        $this->separator = $separator ?? new SideEffectSeparator();
    }

    /**
     * Оптимизирует размещение переменных в AST
     *
     * @param Node[] $ast AST, обработанный SideEffectMarkerVisitor
     * @return Node[] Оптимизированный AST
     */
    public function optimize(array $ast): array
    {
        // 1. Разделяем код по эффектам
        $separation = $this->separator->separate($ast);

        // 2. Находим группы связанных переменных
        $groups = $this->findRelatedVariableGroups($ast);

        // 3. Если групп нет, возвращаем как есть
        if (empty($groups)) {
            return $ast;
        }

        // 4. Переупорядочиваем AST, группируя связанные переменные
        return $this->reorderStatements($ast, $groups, $separation);
    }

    /**
     * Находит группы связанных переменных
     *
     * @param Node[] $ast
     * @return array<int, RelatedVariableGroup>
     */
    private function findRelatedVariableGroups(array $ast): array
    {
        $groups = [];
        $assignments = [];

        // Собираем все присваивания переменных
        foreach ($ast as $index => $node) {
            if (!$node instanceof Node\Stmt\Expression) {
                continue;
            }

            $expr = $node->expr;
            if (!$expr instanceof Node\Expr\Assign) {
                continue;
            }

            $var = $expr->var;
            if (!$var instanceof Node\Expr\Variable || !is_string($var->name)) {
                continue;
            }

            $assignments[$index] = [
                'var' => '$' . $var->name,
                'expr' => $expr->expr,
                'node' => $node,
                'index' => $index,
            ];
        }

        // Ищем связанные присваивания
        foreach ($assignments as $index1 => $assign1) {
            $related = [$assign1];

            foreach ($assignments as $index2 => $assign2) {
                if ($index1 === $index2) {
                    continue;
                }

                // Проверяем, связаны ли переменные
                if ($this->areRelated($assign1, $assign2)) {
                    $related[] = $assign2;
                }
            }

            // Если нашли связанные переменные, создаем группу
            if (count($related) >= 2) {
                $groups[] = new RelatedVariableGroup($related);
            }
        }

        // Удаляем дубликаты групп
        return $this->deduplicateGroups($groups);
    }

    /**
     * Проверяет, связаны ли два присваивания
     */
    private function areRelated(array $assign1, array $assign2): bool
    {
        $expr1 = $assign1['expr'];
        $expr2 = $assign2['expr'];

        // Проверяем доступ к одному источнику (например, $_GET['x'] и $_GET['y'])
        if ($expr1 instanceof Node\Expr\ArrayDimFetch &&
            $expr2 instanceof Node\Expr\ArrayDimFetch) {
            return $this->isSameArray($expr1->var, $expr2->var);
        }

        // Проверяем доступ к свойствам одного объекта
        if ($expr1 instanceof Node\Expr\PropertyFetch &&
            $expr2 instanceof Node\Expr\PropertyFetch) {
            return $this->isSameVariable($expr1->var, $expr2->var);
        }

        return false;
    }

    /**
     * Проверяет, является ли это одним и тем же массивом
     */
    private function isSameArray(Node $var1, Node $var2): bool
    {
        if ($var1 instanceof Node\Expr\Variable &&
            $var2 instanceof Node\Expr\Variable &&
            is_string($var1->name) &&
            is_string($var2->name)) {
            return $var1->name === $var2->name;
        }

        return false;
    }

    /**
     * Проверяет, является ли это одной и той же переменной
     */
    private function isSameVariable(Node $var1, Node $var2): bool
    {
        return $this->isSameArray($var1, $var2);
    }

    /**
     * Удаляет дублирующиеся группы
     *
     * @param RelatedVariableGroup[] $groups
     * @return RelatedVariableGroup[]
     */
    private function deduplicateGroups(array $groups): array
    {
        $unique = [];
        $seen = [];

        foreach ($groups as $group) {
            $key = $group->getSignature();
            if (!isset($seen[$key])) {
                $unique[] = $group;
                $seen[$key] = true;
            }
        }

        return $unique;
    }

    /**
     * Переупорядочивает statements, группируя связанные переменные
     *
     * @param Node[] $ast
     * @param RelatedVariableGroup[] $groups
     * @param SeparationResult $separation
     * @return Node[]
     */
    private function reorderStatements(
        array $ast,
        array $groups,
        SeparationResult $separation
    ): array {
        // Пока просто возвращаем AST как есть
        // Полная реализация требует сложной логики переупорядочивания
        // с учетом зависимостей
        return $ast;
    }
}
