<?php

namespace Recombinator\Visitor;

use PhpParser\Node;
use PhpParser\NodeTraverser;

/**
 * Просто удаление
 */
class RemoveVisitor extends BaseVisitor
{
    public function enterNode(Node $node)
    {
        if ($node instanceof Node\Stmt\InlineHTML) {
            $node->setAttribute('remove', true);
            return NodeTraverser::DONT_TRAVERSE_CHILDREN;
        }
    }
}