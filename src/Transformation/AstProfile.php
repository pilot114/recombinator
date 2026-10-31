<?php

declare(strict_types=1);

namespace Recombinator\Transformation;

use PhpParser\Node;

/**
 * Сводка AST за один обход: сколько узлов каждого класса и сколько узлов в их поддеревьях.
 *
 * Оценки правил (BaseVisitor::estimate) читают только её: один обход на версию AST
 * вместо обхода каждым правилом.
 */
final class AstProfile
{
    /** Число узлов во всём AST. */
    public private(set) int $size = 0;

    /** @var array<class-string<Node>, array{int, int}> класс узла → [число узлов, узлов в их поддеревьях] */
    private array $byClass = [];

    /** @var array<string, array{int, int}> */
    private array $matched = [];

    /**
     * @param array<Node> $ast
     */
    public function __construct(array $ast)
    {
        foreach ($ast as $node) {
            $this->size += $this->visit($node);
        }
    }

    /**
     * Узлы, которые instanceof хотя бы одного из типов (поддеревья вложенных совпадений
     * считаются по разу на каждое совпадение — это верхняя оценка).
     *
     * @param  list<class-string<Node>> $types
     * @return array{int, int} [число узлов, узлов в их поддеревьях]
     */
    public function match(array $types): array
    {
        $key = implode(',', $types);
        if (isset($this->matched[$key])) {
            return $this->matched[$key];
        }

        $count = 0;
        $nodes = 0;
        foreach ($this->byClass as $class => [$c, $n]) {
            foreach ($types as $type) {
                if (is_a($class, $type, true)) {
                    $count += $c;
                    $nodes += $n;
                    break;
                }
            }
        }

        return $this->matched[$key] = [$count, $nodes];
    }

    /** Размер поддерева узла. */
    private function visit(Node $node): int
    {
        $size = 1;
        foreach ($node->getSubNodeNames() as $name) {
            $sub = $node->$name;
            if ($sub instanceof Node) {
                $size += $this->visit($sub);
            } elseif (is_array($sub)) {
                foreach ($sub as $item) {
                    if ($item instanceof Node) {
                        $size += $this->visit($item);
                    }
                }
            }
        }

        $entry = $this->byClass[$node::class] ?? [0, 0];
        $this->byClass[$node::class] = [$entry[0] + 1, $entry[1] + $size];

        return $size;
    }
}
