<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * Заменяет ключевые слова `self` и `static` на конкретное имя класса
 * во всех позициях: type hints, new self/static(...), self/static::*,
 * а также в аннотациях возвращаемого типа closures/arrowFunctions.
 *
 * Используется при инлайнинге тел методов/конструкторов, чтобы ссылки
 * на текущий класс оставались корректными вне тела класса.
 */
class SelfStaticReplacer extends NodeVisitorAbstract
{
    public function __construct(private readonly string $className)
    {
    }

    public function enterNode(Node $node): ?Node
    {
        if (
            $node instanceof Node\Name
            && in_array($node->toString(), ['self', 'static'], true)
        ) {
            return new Node\Name($this->className);
        }

        return null;
    }
}
