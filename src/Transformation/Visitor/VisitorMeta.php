<?php

namespace Recombinator\Transformation\Visitor;

/**
 * Атрибут для описания visitor'а — имя и описание шага трансформации.
 * Используется в bin/inliner.php вместо явных 'name'/'desc' в массиве $steps.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class VisitorMeta
{
    public function __construct(
        public readonly string $desc,
        public readonly string $name = '',
    ) {
    }
}
