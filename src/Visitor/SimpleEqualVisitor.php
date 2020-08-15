<?php

namespace Recombinator\Visitor;

use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Function_;
use PhpParser\NodeTraverser;

/**
 * Выполняет простейшие эквивалентные замены выражений
 *
 * - Бинарные операции (математика, логика, конкатенация)
 * - Подмена переменных их значениями (скаляры)
 * - if (isset(var)) var2 = var;    =>    var2 = var ?? var2;
 */
class SimpleEqualVisitor extends BaseVisitor
{
    protected $scopeVarsReplace = [];
    protected $modifyCount = 0;

    public function enterNode(Node $node)
    {
        if ($node instanceof Node\Stmt\InlineHTML) {
            $node->setAttribute('remove', true);
            return NodeTraverser::DONT_TRAVERSE_CHILDREN;
        }

        /**
         * Замена переменных 1/3 - Запись скаляра
         * Переменная "кэширует" значение, поэтому чтобы избежать сайд-эффектов
         * подменяем только переменные со скалярным значением
         */
        if (isset($node->expr) && $node->expr instanceof Assign) {
            $assign = $node->expr;
            $varName = $assign->var->name;
            if ($assign->expr instanceof Node\Scalar) {
                // заносим в кеш
                $varExprScalar = $assign->expr;
                $varExprScalar->setAttributes([]);
                $this->scopeVarsReplace[$varName] = $varExprScalar;
                $node->setAttribute('remove', true);
            }
        }

        /**
         * Замена переменных 2/3 - чтение переменной
         * Если переменная читается и есть в кеше скаляров - можно смело заменять
         */
        if ($node instanceof Node\Expr\Variable) {
            $varName = $node->name;

            $isWrite = false;
            $parent = $node->getAttribute('parent');
            if ($parent instanceof Assign && $parent->var->name === $varName) {
                $isWrite = true;
            }

            if (!$isWrite && isset($this->scopeVarsReplace[$varName])) {
                $node->setAttribute('replace', $this->scopeVarsReplace[$varName]);
            }
        }
    }

    public function leaveNode(Node $node)
    {
        parent::leaveNode($node);

        /**
         * Замена переменных 3/3 - Запись нескаляра
         * Очищаем замену из кеша, если модифицируем переменную не скаляром
         */
        if (isset($node->expr) && $node->expr instanceof Assign) {
            $assign = $node->expr;
            $varName = $assign->var->name;
            if (!$assign->expr instanceof Node\Scalar) {
                if (isset($this->scopeVarsReplace[$varName])) {
                    unset($this->scopeVarsReplace[$varName]);
                }
            }
        }

        /**
         *  Бинарные операции (математика, логика, конкатенация)
         */
        if ($node instanceof Node\Expr\BinaryOp) {
            if ($node->left instanceof Node\Scalar && $node->right instanceof Node\Scalar) {
                if ($node instanceof Node\Expr\BinaryOp\Plus) {
                    $calc = $node->left->value + $node->right->value;
                } else if ($node instanceof Node\Expr\BinaryOp\Minus) {
                    $calc = $node->left->value - $node->right->value;
                } else if ($node instanceof Node\Expr\BinaryOp\Mul) {
                    $calc = $node->left->value * $node->right->value;
                } else if ($node instanceof Node\Expr\BinaryOp\Div) {
                    $calc = $node->left->value / $node->right->value;
                } else if ($node instanceof Node\Expr\BinaryOp\Concat) {
                    $this->modifyCount++;
                    return new Node\Scalar\String_( $node->left->value . $node->right->value );
                } else {
                    throw new \Exception('New BinaryOp! : ' . get_class($node));
                }
                $this->modifyCount++;
                return is_int($calc) ? new Node\Scalar\LNumber($calc) : new Node\Scalar\DNumber($calc);
            }
        }

        /**
         * if (isset(var)) {
         *     var2 = var;
         * }
         * =>
         * var2 = var ?? var2;
         */
        if ($node instanceof Node\Stmt\If_) {
            if ($node->cond instanceof Node\Expr\Isset_ && count($node->stmts) === 1) {
                $expr = $node->stmts[0]->expr;
                if ($expr instanceof Assign) {
                    $this->modifyCount++;
                    $newExp = new Node\Expr\BinaryOp\Coalesce($expr->expr, $expr->var);
                    $newAssign = new Assign($expr->var, $newExp);
                    return new Expression($newAssign);
                }
            }
        }
    }
}