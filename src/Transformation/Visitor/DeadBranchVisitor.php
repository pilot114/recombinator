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

        if ($node instanceof Node\Stmt\Switch_) {
            $result = $this->processSwitch($node);
            if ($result !== null) {
                return $result;
            }
        }

        if ($node instanceof Node\Expr\Match_) {
            $result = $this->processMatch($node);
            if ($result instanceof \PhpParser\Node\Expr) {
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
            if ($node->elseifs !== []) {
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
            if ($node->else instanceof \PhpParser\Node\Stmt\Else_) {
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

    /**
     * @return int|array<Node>|null  null = без изменений
     */
    private function processSwitch(Node\Stmt\Switch_ $node): int|array|null
    {
        $condResult = $this->asScalarValue($node->cond);
        if ($condResult === null) {
            return null;
        }

        [, $condVal] = $condResult;

        // Find matching case index and default index
        $matchingIdx = null;
        $defaultIdx  = null;
        foreach ($node->cases as $idx => $case) {
            if ($case->cond === null) {
                $defaultIdx = $idx;
                continue;
            }

            $caseResult = $this->asScalarValue($case->cond);
            if ($caseResult !== null && $caseResult[1] == $condVal) {
                $matchingIdx = $idx;
                break;
            }
        }

        $startIdx = $matchingIdx ?? $defaultIdx;
        if ($startIdx === null) {
            return NodeTraverser::REMOVE_NODE;
        }

        // Collect stmts from startIdx, stopping at first plain `break`
        $stmts = [];
        $cases  = $node->cases;
        for ($i = $startIdx, $total = count($cases); $i < $total; $i++) {
            foreach ($cases[$i]->stmts as $stmt) {
                if ($stmt instanceof Node\Stmt\Break_ && !$stmt->num instanceof \PhpParser\Node\Expr) {
                    return $stmts !== [] ? $stmts : NodeTraverser::REMOVE_NODE;
                }

                $stmts[] = $stmt;
            }
        }

        return $stmts !== [] ? $stmts : NodeTraverser::REMOVE_NODE;
    }

    private function processMatch(Node\Expr\Match_ $node): ?Node\Expr
    {
        $subjectResult = $this->asScalarValue($node->cond);
        if ($subjectResult === null) {
            return null;
        }

        [, $sv] = $subjectResult;

        $newArms    = [];
        $defaultArm = null;
        $changed    = false;

        foreach ($node->arms as $arm) {
            if ($arm->conds === null) {
                $defaultArm = $arm;
                continue;
            }

            $surviving = [];
            $matched   = false;

            foreach ($arm->conds as $cond) {
                $cv = $this->asScalarValue($cond);
                if ($cv === null) {
                    $surviving[] = $cond;
                } elseif ($cv[1] === $sv) {
                    $matched = true;
                    break;
                } else {
                    $changed = true; // dead condition — dropped
                }
            }

            if ($matched) {
                return $arm->body;
            }

            if ($surviving !== []) {
                if (count($surviving) !== count($arm->conds)) {
                    $changed    = true;
                    $arm->conds = $surviving;
                }

                $newArms[] = $arm;
            } else {
                $changed = true;
            }
        }

        if ($newArms === [] && $defaultArm !== null) {
            return $defaultArm->body;
        }

        if ($changed) {
            if ($defaultArm !== null) {
                $newArms[] = $defaultArm;
            }

            $node->arms = $newArms;
            return $node;
        }

        return null;
    }

    /** @return array{0: true, 1: mixed}|null */
    private function asScalarValue(Node $node): ?array
    {
        if ($node instanceof Node\Scalar\LNumber) {
            return [true, $node->value];
        }

        if ($node instanceof Node\Scalar\String_) {
            return [true, $node->value];
        }

        if ($node instanceof Node\Scalar\DNumber) {
            return [true, $node->value];
        }

        if ($node instanceof Node\Expr\ConstFetch) {
            return match (strtolower($node->name->toString())) {
                'true'  => [true, true],
                'false' => [true, false],
                'null'  => [true, null],
                default => null,
            };
        }

        if ($node instanceof Node\Expr\UnaryMinus && $node->expr instanceof Node\Scalar\LNumber) {
            return [true, -$node->expr->value];
        }

        return null;
    }
}
