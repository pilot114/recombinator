<?php

declare(strict_types=1);

namespace Recombinator\Analysis;

use PhpParser\Node;
use PhpParser\NodeTraverser;

/**
 * Анализатор использования переменных
 *
 * Анализирует узлы AST для определения:
 * - Используемых переменных (читаются, но не определены в блоке)
 * - Определенных переменных (присваиваются в блоке)
 *
 * Используется для:
 * - Определения параметров функции при выделении блока
 * - Анализа зависимостей между блоками
 * - Оптимизации размещения объявлений переменных
 */
class VariableAnalyzer
{
    /**
     * Анализирует использование переменных в узле или массиве узлов
     *
     * @param  Node|Node[] $nodes
     * @return array{used: array<string>, defined: array<string>}
     */
    public function analyze(Node|array $nodes): array
    {
        if ($nodes instanceof Node) {
            $nodes = [$nodes];
        }

        $visitor = new VariableVisitor();
        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);

        foreach ($nodes as $node) {
            $traverser->traverse([$node]);
        }

        return [
            'used' => $visitor->getUsedVariables(),
            'defined' => $visitor->getDefinedVariables(),
        ];
    }

    /**
     * Возвращает переменные, которые используются, но не определены (параметры)
     *
     * @param  Node|Node[] $nodes
     * @return array<string>
     */
    public function getParameters(Node|array $nodes): array
    {
        $result = $this->analyze($nodes);
        return array_values(array_diff($result['used'], $result['defined']));
    }

    /**
     * Возвращает внутренние переменные (определены, но не используются снаружи)
     *
     * @param  Node|Node[] $nodes
     * @return array<string>
     */
    public function getLocalVariables(Node|array $nodes): array
    {
        $result = $this->analyze($nodes);
        return array_values(array_diff($result['defined'], $result['used']));
    }
}
