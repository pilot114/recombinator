<?php

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;

/**
 * Удаляет все комментарии из кода:
 * однострочные (//), многострочные (/* *\/), PHPDoc (/** *\/)
 */
#[VisitorMeta('Удаление всех комментариев: //, /* */, /** */')]
class RemoveCommentsVisitor extends BaseVisitor
{
    public function enterNode(Node $node): int|Node|array|null
    {
        $node->setAttribute('comments', []);

        return null;
    }
}
