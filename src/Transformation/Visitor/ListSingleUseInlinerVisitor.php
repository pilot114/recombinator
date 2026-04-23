<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;

/**
 * Оптимизация деструктуризации списков с одним используемым элементом.
 *
 * Если из list($a, $b, ...) = expr или [$a, $b] = expr ровно одна переменная
 * читается ровно один раз, а остальные нигде не используются — присваивание
 * удаляется, переменная заменяется прямым индексным доступом expr[N].
 *
 * Пример:
 *   list($s, $p) = func($a, $b, 0);
 *   return $s;
 * →
 *   return func($a, $b, 0)[0];
 *
 * Условие безопасности: при нескольких используемых переменных expr пришлось бы
 * вычислять несколько раз — такие случаи не оптимизируются.
 */
#[VisitorMeta('list($s, $unused) = expr; use($s) → use(expr[0])')]
class ListSingleUseInlinerVisitor extends BaseVisitor
{
    /** @var array<string, array{expr: Node\Expr, index: int, stmtNode: Node\Stmt\Expression}> */
    private array $inlineable = [];

    #[\Override]
    public function beforeTraverse(array $nodes): ?array
    {
        parent::beforeTraverse($nodes);

        $this->inlineable = [];

        /** @var array<string, array{expr: Node\Expr, index: int, stmtNode: Node\Stmt\Expression, assignNode: Node\Expr\Assign}> */
        $candidates = [];
        /** @var array<string, int> */
        $readCounts = [];

        foreach ($this->findNode(Node\Expr\Variable::class) as $var) {
            if (!is_string($var->name)) {
                continue;
            }

            $name = $var->name;

            if ($this->isDestructuringLhsVar($var)) {
                // Переменная является LHS деструктуризации — определяем её индекс
                $arrayItem   = $var->getAttribute('parent'); // ArrayItem
                $destructure = $arrayItem instanceof Node\Expr\ArrayItem ? $arrayItem->getAttribute('parent') : null;
                $assignNode  = $this->isDestructuringNode($destructure) ? $destructure->getAttribute('parent') : null;
                $stmtNode    = $assignNode instanceof Node\Expr\Assign ? $assignNode->getAttribute('parent') : null;

                if (
                    $this->isDestructuringNode($destructure)
                    && $assignNode instanceof Node\Expr\Assign
                    && $stmtNode instanceof Node\Stmt\Expression
                ) {
                    $index = 0;
                    foreach ($destructure->items as $i => $item) {
                        if ($item instanceof Node\Expr\ArrayItem && $item->value === $var) {
                            $index = $i;
                            break;
                        }
                    }

                    $candidates[$name] = [
                        'expr'       => $assignNode->expr,
                        'index'      => $index,
                        'stmtNode'   => $stmtNode,
                        'assignNode' => $assignNode,
                    ];
                }

                continue; // не считать LHS как чтение
            }

            // Не считать объявления параметров функции
            $parent = $var->getAttribute('parent');
            if ($parent instanceof Node\Param) {
                continue;
            }

            $readCounts[$name] = ($readCounts[$name] ?? 0) + 1;
        }

        foreach ($candidates as $varName => $info) {
            if (($readCounts[$varName] ?? 0) !== 1) {
                continue;
            }

            // Безопасно инлайнить только если все остальные переменные неиспользуемы:
            // при нескольких используемых переменных expr вычислился бы несколько раз.
            $usedVarsCount = 0;
            foreach ($info['assignNode']->var->items as $item) {
                if (!$item instanceof Node\Expr\ArrayItem) {
                    continue;
                }

                $v = $item->value;
                if (!$v instanceof Node\Expr\Variable || !is_string($v->name)) {
                    continue;
                }

                $rc = $readCounts[$v->name] ?? 0;
                if ($rc > 1) {
                    $usedVarsCount = 99; // многократное использование — нельзя инлайнить
                    break;
                }

                if ($rc === 1) {
                    $usedVarsCount++;
                }
            }

            if ($usedVarsCount !== 1) {
                continue;
            }

            $this->inlineable[$varName] = $info;
            $info['stmtNode']->setAttribute('remove', true);
        }

        return null;
    }

    #[\Override]
    public function enterNode(Node $node): ?Node
    {
        if (!$node instanceof Node\Expr\Variable || !is_string($node->name)) {
            return null;
        }

        if (!isset($this->inlineable[$node->name]) || $this->isDestructuringLhsVar($node)) {
            return null;
        }

        $info = $this->inlineable[$node->name];
        unset($this->inlineable[$node->name]);

        return new Node\Expr\ArrayDimFetch(
            $this->deepClone($info['expr']),
            new Node\Scalar\LNumber($info['index'])
        );
    }

    /**
     * Возвращает true если переменная является частью LHS деструктуризации
     * (как list($s, ...) = expr, так и [$s, ...] = expr).
     */
    private function isDestructuringLhsVar(Node\Expr\Variable $var): bool
    {
        $parent = $var->getAttribute('parent');
        if (!$parent instanceof Node\Expr\ArrayItem) {
            return false;
        }

        $destructure = $parent->getAttribute('parent');
        if (!$this->isDestructuringNode($destructure)) {
            return false;
        }

        $assign = $destructure->getAttribute('parent');
        return $assign instanceof Node\Expr\Assign && $assign->var === $destructure;
    }

    /**
     * Возвращает true для List_ (list(...)) и Array_ (короткий синтаксис [...]).
     */
    private function isDestructuringNode(mixed $node): bool
    {
        return $node instanceof Node\Expr\List_ || $node instanceof Node\Expr\Array_;
    }
}
