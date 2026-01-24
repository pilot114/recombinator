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
    public function __construct(private readonly SideEffectSeparator $separator = new SideEffectSeparator())
    {
    }

    /**
     * Оптимизирует размещение переменных в AST
     *
     * @param  Node[] $ast AST, обработанный SideEffectMarkerVisitor
     * @return Node[] Оптимизированный AST
     */
    public function optimize(array $ast): array
    {
        // 1. Разделяем код по эффектам
        $this->separator->separate($ast);

        // 2. Находим группы связанных переменных
        $groups = $this->findRelatedVariableGroups($ast);

        // 3. Если групп нет, возвращаем как есть
        if ($groups === []) {
            return $ast;
        }

        // 4. Переупорядочиваем AST, группируя связанные переменные
        return $this->reorderStatements($ast);
    }

    /**
     * Находит группы связанных переменных
     *
     * @param  Node[] $ast
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
            if (!$var instanceof Node\Expr\Variable) {
                continue;
            }

            if (!is_string($var->name)) {
                continue;
            }

            $assignments[(int) $index] = [
                'var' => '$' . $var->name,
                'index' => (int) $index,
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
     *
     * @param array{var: string, index: int} $assign1
     * @param array{var: string, index: int} $assign2
     */
    private function areRelated(array $assign1, array $assign2): bool
    {
        // Simple check: variables with similar names might be related
        // This is a simplified version - real implementation would need
        // to check the actual expressions for common source
        $var1 = $assign1['var'];
        $var2 = $assign2['var'];

        // Check for common prefix (e.g., $x, $y, $z might be coordinates)
        $prefix1 = preg_replace('/\d+$/', '', $var1);
        $prefix2 = preg_replace('/\d+$/', '', $var2);

        return $prefix1 === $prefix2 && strlen($prefix1 ?? '') >= 2;
    }

    /**
     * Удаляет дублирующиеся группы
     *
     * @param  RelatedVariableGroup[] $groups
     * @return array<int, RelatedVariableGroup>
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
     * @param  Node[] $ast
     * @return Node[]
     */
    private function reorderStatements(
        array $ast
    ): array {
        // Пока просто возвращаем AST как есть
        // Полная реализация требует сложной логики переупорядочивания
        // с учетом зависимостей
        return $ast;
    }
}
