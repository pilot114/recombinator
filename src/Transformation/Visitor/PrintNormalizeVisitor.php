<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;

/**
 * Нормализация вывода:
 * - print $x  →  echo $x  (как statement)
 * - PHP_EOL   →  "\n"
 */
#[VisitorMeta('Нормализация вывода: print → echo, PHP_EOL → "\n"')]
class PrintNormalizeVisitor extends BaseVisitor
{
    #[\Override]
    public function leaveNode(Node $node): int|Node|array|null
    {
        // print $expr; → echo $expr;
        if (
            $node instanceof Node\Stmt\Expression
            && $node->expr instanceof Node\Expr\Print_
        ) {
            return new Node\Stmt\Echo_([$node->expr->expr]);
        }

        // PHP_EOL → "\n"
        if (
            $node instanceof Node\Expr\ConstFetch
            && $node->name->toString() === 'PHP_EOL'
        ) {
            return new Node\Scalar\String_("\n");
        }

        return parent::leaveNode($node);
    }
}
