<?php

declare(strict_types=1);

namespace Recombinator\Analysis;

use PhpParser\Node;
use PhpParser\NodeTraverser;

/**
 * Калькулятор когнитивной сложности кода
 *
 * Реализует метрику когнитивной сложности согласно docs/rules.md:
 *
 * Критерии начисления баллов:
 * - Каждая бинарная операция: +1 балл
 * - Каждый вызов функции: +2 балла
 * - Каждый уровень вложенности: +1 балл
 * - Каждый доступ к массиву/свойству: +1 балл
 *
 * Классификация сложности:
 * - Простое (0-2 балла): $x + $y, $arr[0], strlen($str)
 * - Среднее (3-5 баллов): $x * $y + $z, $arr[$key]['value'], hash('sha256', $data)
 * - Сложное (6+ баллов): sqrt($x * $x + $y * $y), вложенные вызовы функций
 */
class CognitiveComplexityCalculator
{
    /**
     * Вычисляет когнитивную сложность узла или массива узлов
     *
     * @param Node|Node[] $nodes
     * @return int Сложность в баллах
     */
    public function calculate(Node|array $nodes): int
    {
        if ($nodes instanceof Node) {
            $nodes = [$nodes];
        }

        $visitor = new ComplexityVisitor();
        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);

        foreach ($nodes as $node) {
            $traverser->traverse([$node]);
        }

        return $visitor->getComplexity();
    }

    /**
     * Определяет уровень сложности (простой, средний, сложный)
     */
    public function getComplexityLevel(int $complexity): string
    {
        return match (true) {
            $complexity <= 2 => 'simple',
            $complexity <= 5 => 'medium',
            default => 'complex',
        };
    }
}

