<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;

/**
 * Removes variables from closure `use(...)` lists when the variable
 * is not actually referenced inside the closure body:
 *
 *   function () use ($shelter): string {
 *       throw new RuntimeException('x');
 *   }
 *   → function (): string { throw new RuntimeException('x'); }
 */
#[VisitorMeta('Удаление неиспользуемых переменных из use(...) замыканий')]
class RemoveUnusedClosureCapturesVisitor extends BaseVisitor
{
    #[\Override]
    public function leaveNode(Node $node): int|Node|array|null
    {
        if (!$node instanceof Node\Expr\Closure || $node->uses === []) {
            return parent::leaveNode($node);
        }

        // Collect variable names actually referenced in the body
        $referenced = [];
        foreach ($this->findNode(Node\Expr\Variable::class, $node->stmts ?? []) as $var) {
            if (is_string($var->name)) {
                $referenced[$var->name] = true;
            }
        }

        $filtered = array_values(array_filter(
            $node->uses,
            fn(\PhpParser\Node\ClosureUse $use): bool =>
                $use instanceof Node\Expr\ClosureUse
                && $use->var instanceof Node\Expr\Variable
                && is_string($use->var->name)
                && isset($referenced[$use->var->name])
        ));

        if (count($filtered) === count($node->uses)) {
            return parent::leaveNode($node);
        }

        $clone        = clone $node;
        $clone->uses  = $filtered;
        return $clone;
    }
}
