<?php

declare(strict_types=1);

namespace Recombinator;

use PhpParser\Node;

/**
 * Поиск чистых блоков кода
 *
 * Находит последовательные участки кода без побочных эффектов (PURE),
 * которые можно безопасно:
 * - Вычислить в compile-time
 * - Переупорядочить
 * - Инлайнить
 * - Кешировать результаты
 *
 * Чистый блок - это последовательность statement'ов, где каждый:
 * 1. Помечен как PURE (через SideEffectMarkerVisitor)
 * 2. Не зависит от внешнего состояния
 * 3. Не изменяет внешнее состояние
 */
class PureBlockFinder
{
    /**
     * Найденные чистые блоки
     *
     * @var array<int, array{start: int, end: int, nodes: Node[], size: int}>
     */
    private array $blocks = [];

    /**
     * Минимальный размер блока (количество узлов)
     * Блоки меньше этого размера игнорируются
     */
    private int $minBlockSize;

    /**
     * @param int $minBlockSize Минимальный размер блока для поиска
     */
    public function __construct(int $minBlockSize = 1)
    {
        $this->minBlockSize = max(1, $minBlockSize);
    }

    /**
     * Ищет чистые блоки в AST
     *
     * AST должен быть предварительно обработан SideEffectMarkerVisitor
     *
     * @param Node[] $ast AST с помеченными узлами
     * @return array<int, array{start: int, end: int, nodes: Node[], size: int}>
     */
    public function findBlocks(array $ast): array
    {
        $this->blocks = [];

        // Извлекаем только statement узлы верхнего уровня
        $statements = $this->extractStatements($ast);

        if (empty($statements)) {
            return [];
        }

        // Ищем последовательности чистых statement'ов
        $currentBlock = [];
        $currentStart = 0;

        foreach ($statements as $index => $stmt) {
            if ($this->isPureNode($stmt)) {
                // Узел чистый - добавляем в текущий блок
                if (empty($currentBlock)) {
                    $currentStart = $index;
                }
                $currentBlock[] = $stmt;
            } else {
                // Узел не чистый - завершаем текущий блок
                if (count($currentBlock) >= $this->minBlockSize) {
                    $this->blocks[] = [
                        'start' => $currentStart,
                        'end' => $index - 1,
                        'nodes' => $currentBlock,
                        'size' => count($currentBlock),
                    ];
                }
                $currentBlock = [];
            }
        }

        // Сохраняем последний блок, если он есть
        if (count($currentBlock) >= $this->minBlockSize) {
            $this->blocks[] = [
                'start' => $currentStart,
                'end' => count($statements) - 1,
                'nodes' => $currentBlock,
                'size' => count($currentBlock),
            ];
        }

        return $this->blocks;
    }

    /**
     * Извлекает statement узлы из AST
     *
     * @param Node[] $ast
     * @return Node\Stmt[]
     */
    private function extractStatements(array $ast): array
    {
        $statements = [];

        foreach ($ast as $node) {
            if ($node instanceof Node\Stmt) {
                $statements[] = $node;
            }
        }

        return $statements;
    }

    /**
     * Проверяет, является ли узел чистым
     */
    private function isPureNode(Node $node): bool
    {
        $effect = $node->getAttribute('side_effect');

        if (!$effect) {
            // Узел не помечен - считаем не чистым для безопасности
            return false;
        }

        return $effect === SideEffectType::PURE;
    }

    /**
     * Возвращает все найденные чистые блоки
     *
     * @return array<int, array{start: int, end: int, nodes: Node[], size: int}>
     */
    public function getBlocks(): array
    {
        return $this->blocks;
    }

    /**
     * Возвращает количество найденных блоков
     */
    public function getBlockCount(): int
    {
        return count($this->blocks);
    }

    /**
     * Возвращает общее количество чистых узлов во всех блоках
     */
    public function getTotalPureNodes(): int
    {
        return array_sum(array_column($this->blocks, 'size'));
    }

    /**
     * Возвращает самый большой чистый блок
     *
     * @return array{start: int, end: int, nodes: Node[], size: int}|null
     */
    public function getLargestBlock(): ?array
    {
        if (empty($this->blocks)) {
            return null;
        }

        $largest = $this->blocks[0];
        foreach ($this->blocks as $block) {
            if ($block['size'] > $largest['size']) {
                $largest = $block;
            }
        }

        return $largest;
    }

    /**
     * Возвращает блоки, отсортированные по размеру (от большего к меньшему)
     *
     * @return array<int, array{start: int, end: int, nodes: Node[], size: int}>
     */
    public function getBlocksSortedBySize(): array
    {
        $blocks = $this->blocks;
        usort($blocks, fn($a, $b) => $b['size'] <=> $a['size']);
        return $blocks;
    }

    /**
     * Находит чистые блоки внутри составных узлов (if, while, etc.)
     *
     * @param Node[] $ast AST с помеченными узлами
     * @return array<string, array<int, array{start: int, end: int, nodes: Node[], size: int, context: string}>>
     */
    public function findNestedBlocks(array $ast): array
    {
        $nestedBlocks = [];

        foreach ($ast as $node) {
            if (!$node instanceof Node) {
                continue;
            }

            // Ищем составные statement'ы
            if ($node instanceof Node\Stmt\If_) {
                $nestedBlocks['if'][] = $this->findBlocksInContext($node->stmts, 'if_then');
                if ($node->else) {
                    $nestedBlocks['if'][] = $this->findBlocksInContext($node->else->stmts, 'if_else');
                }
                foreach ($node->elseifs as $elseif) {
                    $nestedBlocks['if'][] = $this->findBlocksInContext($elseif->stmts, 'if_elseif');
                }
            } elseif ($node instanceof Node\Stmt\While_) {
                $nestedBlocks['while'][] = $this->findBlocksInContext($node->stmts, 'while_body');
            } elseif ($node instanceof Node\Stmt\For_) {
                $nestedBlocks['for'][] = $this->findBlocksInContext($node->stmts, 'for_body');
            } elseif ($node instanceof Node\Stmt\Foreach_) {
                $nestedBlocks['foreach'][] = $this->findBlocksInContext($node->stmts, 'foreach_body');
            } elseif ($node instanceof Node\Stmt\Function_) {
                $nestedBlocks['function'][] = $this->findBlocksInContext($node->stmts, 'function_' . ($node->name->name ?? 'anonymous'));
            } elseif ($node instanceof Node\Stmt\ClassMethod) {
                $nestedBlocks['method'][] = $this->findBlocksInContext($node->stmts ?? [], 'method_' . $node->name->name);
            } elseif ($node instanceof Node\Stmt\TryCatch) {
                $nestedBlocks['try'][] = $this->findBlocksInContext($node->stmts, 'try_block');
                foreach ($node->catches as $catch) {
                    $nestedBlocks['try'][] = $this->findBlocksInContext($catch->stmts, 'catch_block');
                }
                if ($node->finally) {
                    $nestedBlocks['try'][] = $this->findBlocksInContext($node->finally->stmts, 'finally_block');
                }
            }
        }

        // Фильтруем пустые результаты
        return array_filter($nestedBlocks, fn($blocks) => !empty($blocks));
    }

    /**
     * Находит чистые блоки в контексте (например, внутри if или while)
     *
     * @param Node[] $stmts Statement'ы для анализа
     * @param string $context Контекст (например, 'if_then', 'while_body')
     * @return array{start: int, end: int, nodes: Node[], size: int, context: string}[]
     */
    private function findBlocksInContext(array $stmts, string $context): array
    {
        $blocks = [];
        $currentBlock = [];
        $currentStart = 0;

        foreach ($stmts as $index => $stmt) {
            if ($this->isPureNode($stmt)) {
                if (empty($currentBlock)) {
                    $currentStart = $index;
                }
                $currentBlock[] = $stmt;
            } else {
                if (count($currentBlock) >= $this->minBlockSize) {
                    $blocks[] = [
                        'start' => $currentStart,
                        'end' => $index - 1,
                        'nodes' => $currentBlock,
                        'size' => count($currentBlock),
                        'context' => $context,
                    ];
                }
                $currentBlock = [];
            }
        }

        // Сохраняем последний блок
        if (count($currentBlock) >= $this->minBlockSize) {
            $blocks[] = [
                'start' => $currentStart,
                'end' => count($stmts) - 1,
                'nodes' => $currentBlock,
                'size' => count($currentBlock),
                'context' => $context,
            ];
        }

        return $blocks;
    }

    /**
     * Возвращает статистику по чистым блокам
     *
     * @return array{
     *   total_blocks: int,
     *   total_pure_nodes: int,
     *   average_block_size: float,
     *   largest_block_size: int,
     *   smallest_block_size: int
     * }
     */
    public function getStats(): array
    {
        if (empty($this->blocks)) {
            return [
                'total_blocks' => 0,
                'total_pure_nodes' => 0,
                'average_block_size' => 0.0,
                'largest_block_size' => 0,
                'smallest_block_size' => 0,
            ];
        }

        $sizes = array_column($this->blocks, 'size');

        return [
            'total_blocks' => count($this->blocks),
            'total_pure_nodes' => array_sum($sizes),
            'average_block_size' => round(array_sum($sizes) / count($sizes), 2),
            'largest_block_size' => max($sizes),
            'smallest_block_size' => min($sizes),
        ];
    }
}
