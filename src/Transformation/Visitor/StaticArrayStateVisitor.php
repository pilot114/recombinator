<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;
use PhpParser\NodeTraverser;

/**
 * Отслеживает состояние массивов, известных на этапе компиляции,
 * и заменяет соответствующие операции константами:
 *
 *   $bag = ['key' => 'value'];
 *   if (isset($bag['key']) && !empty($bag['key'])) { unset($bag['key']); }
 *   $x = empty($bag) ? 'empty' : 'not';
 *   →
 *   $x = 'empty';
 *
 * Обрабатывает только переменные верхнего уровня с литеральными ключами.
 */
#[VisitorMeta('Статическое отслеживание состояния массивов (isset/empty/unset)')]
class StaticArrayStateVisitor extends BaseVisitor
{
    /** @var array<string, array<int|string, mixed>> varName → [key → value] */
    private array $state = [];

    private int $methodDepth = 0;

    #[\Override]
    public function beforeTraverse(array $nodes): ?array
    {
        parent::beforeTraverse($nodes);
        $this->state      = [];
        $this->methodDepth = 0;
        return null;
    }

    #[\Override]
    public function enterNode(Node $node): int|Node|array|null
    {
        if ($node instanceof Node\Stmt\ClassMethod || $node instanceof Node\Stmt\Function_) {
            $this->methodDepth++;
        }

        return null;
    }

    #[\Override]
    public function leaveNode(Node $node): int|Node|array|null
    {
        if ($node instanceof Node\Stmt\ClassMethod || $node instanceof Node\Stmt\Function_) {
            $this->methodDepth--;
            return parent::leaveNode($node);
        }

        if ($this->methodDepth > 0) {
            return parent::leaveNode($node);
        }

        // Track $arr = [k => v, ...]
        if (
            $node instanceof Node\Stmt\Expression
            && $node->expr instanceof Node\Expr\Assign
            && $node->expr->var instanceof Node\Expr\Variable
            && is_string($node->expr->var->name)
            && $node->expr->expr instanceof Node\Expr\Array_
        ) {
            $varName = $node->expr->var->name;
            $extracted = $this->extractArrayLiteral($node->expr->expr);
            if ($extracted !== null) {
                $this->state[$varName] = $extracted;
            }

            return parent::leaveNode($node);
        }

        // Invalidate state when $arr is assigned a non-array value
        if (
            $node instanceof Node\Stmt\Expression
            && $node->expr instanceof Node\Expr\Assign
            && $node->expr->var instanceof Node\Expr\Variable
            && is_string($node->expr->var->name)
            && !($node->expr->expr instanceof Node\Expr\Array_)
        ) {
            $varName = $node->expr->var->name;
            unset($this->state[$varName]);
            return parent::leaveNode($node);
        }

        // unset($arr['key']) with known state → update state, remove statement
        if ($node instanceof Node\Stmt\Unset_) {
            $updates = [];
            foreach ($node->vars as $var) {
                if (
                    !($var instanceof Node\Expr\ArrayDimFetch)
                    || !($var->var instanceof Node\Expr\Variable)
                    || !is_string($var->var->name)
                ) {
                    return parent::leaveNode($node);
                }

                $varName = $var->var->name;
                if (!array_key_exists($varName, $this->state)) {
                    return parent::leaveNode($node);
                }

                $key = $this->extractKeyNode($var->dim);
                if ($key === null) {
                    return parent::leaveNode($node);
                }

                $updates[] = [$varName, $key];
            }

            foreach ($updates as [$varName, $key]) {
                unset($this->state[$varName][$key]);
            }

            $node->setAttribute('remove', true);
            return parent::leaveNode($node);
        }

        // isset($arr['key']) → true/false
        if ($node instanceof Node\Expr\Isset_) {
            $result = $this->evaluateIsset($node);
            if ($result !== null) {
                return $this->boolNode($result);
            }

            return parent::leaveNode($node);
        }

        // empty($arr) or empty($arr['key']) → true/false
        if ($node instanceof Node\Expr\Empty_) {
            $result = $this->evaluateEmpty($node->expr);
            if ($result !== null) {
                return $this->boolNode($result);
            }

            return parent::leaveNode($node);
        }

        return parent::leaveNode($node);
    }

    /** @return array<int|string, mixed>|null */
    private function extractArrayLiteral(Node\Expr\Array_ $node): ?array
    {
        $result = [];
        $idx    = 0;
        foreach ($node->items as $item) {
            if ($item === null) {
                return null;
            }

            if ($item->key !== null) {
                $key = $this->extractScalarValue($item->key);
                if ($key === null) {
                    return null;
                }
            } else {
                $key = $idx;
            }

            $val = $this->extractScalarValue($item->value);
            if ($val === null && !($item->value instanceof Node\Expr\ConstFetch)) {
                return null;
            }

            $result[$key] = $val;
            $idx++;
        }

        return $result;
    }

    private function extractKeyNode(?Node\Expr $dim): int|string|null
    {
        if (!$dim instanceof \PhpParser\Node\Expr) {
            return null;
        }

        return $this->extractScalarValue($dim);
    }

    private function extractScalarValue(Node\Expr $node): int|float|string|null
    {
        if ($node instanceof Node\Scalar\String_) {
            return $node->value;
        }

        if ($node instanceof Node\Scalar\LNumber) {
            return $node->value;
        }

        if ($node instanceof Node\Scalar\DNumber) {
            return $node->value;
        }

        return null;
    }

    private function evaluateIsset(Node\Expr\Isset_ $node): ?bool
    {
        foreach ($node->vars as $var) {
            if (
                $var instanceof Node\Expr\ArrayDimFetch
                && $var->var instanceof Node\Expr\Variable
                && is_string($var->var->name)
            ) {
                $varName = $var->var->name;
                if (!array_key_exists($varName, $this->state)) {
                    return null;
                }

                $key = $this->extractKeyNode($var->dim);
                if ($key === null) {
                    return null;
                }

                if (!array_key_exists($key, $this->state[$varName])) {
                    return false;
                }

                if ($this->state[$varName][$key] === null) {
                    return false;
                }
            } else {
                return null;
            }
        }

        return true;
    }

    private function evaluateEmpty(Node\Expr $expr): ?bool
    {
        // empty($arr)
        if ($expr instanceof Node\Expr\Variable && is_string($expr->name)) {
            $varName = $expr->name;
            if (!array_key_exists($varName, $this->state)) {
                return null;
            }

            return $this->state[$varName] === [];
        }

        // empty($arr['key'])
        if (
            $expr instanceof Node\Expr\ArrayDimFetch
            && $expr->var instanceof Node\Expr\Variable
            && is_string($expr->var->name)
        ) {
            $varName = $expr->var->name;
            if (!array_key_exists($varName, $this->state)) {
                return null;
            }

            $key = $this->extractKeyNode($expr->dim);
            if ($key === null) {
                return null;
            }

            if (!array_key_exists($key, $this->state[$varName])) {
                return true;
            }

            return empty($this->state[$varName][$key]);
        }

        return null;
    }

    private function boolNode(bool $val): Node\Expr\ConstFetch
    {
        return new Node\Expr\ConstFetch(new Node\Name($val ? 'true' : 'false'));
    }
}
