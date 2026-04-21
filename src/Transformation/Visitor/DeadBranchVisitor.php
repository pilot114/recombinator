<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;
use PhpParser\NodeTraverser;

/**
 * Удаляет мёртвые ветки условий:
 * - `if (false) { ... }` → удалить
 * - `if (false) { ... } else { CODE }` → CODE
 * - `if (false) { ... } elseif ($x) { CODE }` → `if ($x) { CODE }`
 * - `if (true) { CODE }` → CODE (else/elseif отбрасываются)
 */
#[VisitorMeta('Удаление мёртвых веток if(true/false)')]
class DeadBranchVisitor extends BaseVisitor
{
    #[\Override]
    public function leaveNode(Node $node): int|Node|array|null
    {
        if ($node instanceof Node\Stmt\If_) {
            $result = $this->processIf($node);
            if ($result !== null) {
                return $result;
            }
        }

        return parent::leaveNode($node);
    }

    /**
     * @return int|array<Node>|null  null = без изменений
     */
    private function processIf(Node\Stmt\If_ $node): int|array|null
    {
        if ($this->isConstFalse($node->cond)) {
            // if (false) { ... } elseif ($x) { ... } → if ($x) { ... }
            if (!empty($node->elseifs)) {
                /** @var Node\Stmt\ElseIf_ $first */
                $first      = array_shift($node->elseifs);
                $newIf      = new Node\Stmt\If_(
                    $first->cond,
                    [
                        'stmts'   => $first->stmts,
                        'elseifs' => $node->elseifs,
                        'else'    => $node->else,
                    ]
                );
                return [$newIf];
            }

            // if (false) { ... } else { CODE } → CODE
            if ($node->else !== null) {
                return $node->else->stmts;
            }

            return NodeTraverser::REMOVE_NODE;
        }

        if ($this->isConstTrue($node->cond)) {
            // if (true) { CODE } → CODE (else и elseif недостижимы)
            return $node->stmts ?: NodeTraverser::REMOVE_NODE;
        }

        return null;
    }

    private function isConstFalse(Node $node): bool
    {
        return $node instanceof Node\Expr\ConstFetch
            && strtolower($node->name->toString()) === 'false';
    }

    private function isConstTrue(Node $node): bool
    {
        return $node instanceof Node\Expr\ConstFetch
            && strtolower($node->name->toString()) === 'true';
    }
}
