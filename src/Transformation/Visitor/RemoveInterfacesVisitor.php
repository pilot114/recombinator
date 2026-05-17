<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;

/**
 * Удаляет объявления интерфейсов и их упоминания в implements-списке классов.
 *
 * Что убирается:
 *   - interface Foo { ... }
 *   - class Bar implements Foo, Baz  →  class Bar
 *
 * Что НЕ трогается:
 *   - тайп-хинты параметров и возвращаемых значений (это задача отдельного шага)
 */
#[VisitorMeta('Удаление интерфейсов и implements')]
class RemoveInterfacesVisitor extends BaseVisitor
{
    public function enterNode(Node $node): ?Node
    {
        if ($node instanceof Node\Stmt\Interface_) {
            $node->setAttribute('remove', true);
            return null;
        }

        if ($node instanceof Node\Stmt\Class_ && !empty($node->implements)) {
            $node->implements = [];
        }

        return null;
    }
}
