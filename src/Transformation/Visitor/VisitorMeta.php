<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;

/**
 * Атрибут для описания visitor'а — имя и описание шага трансформации.
 * Используется в bin/inliner.php вместо явных 'name'/'desc' в массиве $steps.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class VisitorMeta
{
    /**
     * @param list<class-string<Node>> $targets узлы, без которых правило не меняет код: нет ни
     *                                          одного такого в AST — правило можно не запускать
     *                                          (BaseVisitor::estimate); пусто — условие неизвестно
     */
    public function __construct(
        public readonly string $desc,
        public readonly string $name = '',
        public readonly array $targets = [],
    ) {
    }

    /**
     * Метаданные правила; без атрибута — имя класса.
     *
     * @param object|class-string $visitor
     */
    public static function of(object|string $visitor): self
    {
        /** @var array<string, self> $cache */
        static $cache = [];
        $class = is_object($visitor) ? $visitor::class : $visitor;
        if (isset($cache[$class])) {
            return $cache[$class];
        }

        $ref   = new \ReflectionClass($class);
        $attrs = $ref->getAttributes(self::class);
        $meta  = $attrs !== [] ? $attrs[0]->newInstance() : new self('');

        return $cache[$class] = new self($meta->desc, $meta->name ?: $ref->getShortName(), $meta->targets);
    }
}
