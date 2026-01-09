<?php

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;
use PhpParser\NodeVisitor;

/**
 * Просто удаление
 */
class RemoveVisitor extends BaseVisitor
{
    public function enterNode(Node $node): ?int
    {
        if ($node instanceof Node\Stmt\InlineHTML) {
            $node->setAttribute('remove', true);
            return NodeVisitor::DONT_TRAVERSE_CHILDREN;
        }

        return null;
    }
}
