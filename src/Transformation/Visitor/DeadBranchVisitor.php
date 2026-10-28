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

        if ($node instanceof Node\Stmt\Do_) {
            $result = $this->processDo($node);
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

    /** Литерал, приводимый к false: false, null, 0, 0.0, '', '0', []. */
    private function isConstFalse(Node $node): bool
    {
        return match (true) {
            $node instanceof Node\Expr\ConstFetch => in_array(strtolower($node->name->toString()), ['false', 'null'], true),
            $node instanceof Node\Scalar\Int_, $node instanceof Node\Scalar\Float_ => $node->value == 0,
            $node instanceof Node\Scalar\String_ => $node->value === '' || $node->value === '0',
            $node instanceof Node\Expr\Array_ => $node->items === [],
            default => false,
        };
    }

    /** Литерал, приводимый к true: true, ненулевое число, непустая строка (кроме '0'). */
    private function isConstTrue(Node $node): bool
    {
        return match (true) {
            $node instanceof Node\Expr\ConstFetch => strtolower($node->name->toString()) === 'true',
            $node instanceof Node\Scalar\Int_, $node instanceof Node\Scalar\Float_ => $node->value != 0,
            $node instanceof Node\Scalar\String_ => $node->value !== '' && $node->value !== '0',
            default => false,
        };
    }

    /**
     * `do { BODY } while (false)` без break/continue, относящихся к этому циклу, — BODY.
     *
     * @return array<Node>|int|null
     */
    private function processDo(Node\Stmt\Do_ $node): array|int|null
    {
        if (!$this->isConstFalse($node->cond) || $this->jumpsOut($node->stmts, 1)) {
            return null;
        }

        return $node->stmts === [] ? NodeTraverser::REMOVE_NODE : $node->stmts;
    }

    /**
     * Есть break/continue, выходящий за $levels уровней циклов (то есть к нашему циклу или
     * дальше). Вложенные функции и классы — своя область.
     *
     * @param array<Node> $nodes
     */
    private function jumpsOut(array $nodes, int $levels): bool
    {
        foreach ($nodes as $node) {
            if ($node instanceof Node\FunctionLike || $node instanceof Node\Stmt\ClassLike) {
                continue;
            }

            if ($node instanceof Node\Stmt\Break_ || $node instanceof Node\Stmt\Continue_) {
                $num = $node->num instanceof Node\Scalar\Int_ ? $node->num->value : ($node->num instanceof \PhpParser\Node\Expr ? PHP_INT_MAX : 1);
                if ($num >= $levels) {
                    return true;
                }

                continue;
            }

            $loop = $node instanceof Node\Stmt\For_ || $node instanceof Node\Stmt\Foreach_ || $node instanceof Node\Stmt\While_
                || $node instanceof Node\Stmt\Do_ || $node instanceof Node\Stmt\Switch_;
            foreach ($node->getSubNodeNames() as $name) {
                $value = $node->$name;
                $children = array_filter(is_array($value) ? $value : [$value], static fn(mixed $c): bool => $c instanceof Node);
                if ($this->jumpsOut($children, $loop ? $levels + 1 : $levels)) {
                    return true;
                }
            }
        }

        return false;
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
            // Неизвестное условие (`case $lexer->isNextToken(...)`) может совпасть —
            // какая ветка выполнится, заранее не знаем
            if ($caseResult === null) {
                return null;
            }

            if ($caseResult[1] == $condVal) {
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

                // break/continue глубже (`if (...) { ...; break; }`) относится к этому switch:
                // вне его он станет ошибкой компиляции или выйдет из внешнего цикла
                if ($this->findNode(Node\Stmt\Break_::class, $stmt) !== [] || $this->findNode(Node\Stmt\Continue_::class, $stmt) !== []) {
                    return null;
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
