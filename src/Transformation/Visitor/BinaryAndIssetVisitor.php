<?php

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Stmt\Expression;

/**
 * - Бинарные операции (математика, логика, конкатенация)
 * - if (isset(var)) var2 = var;    =>    var2 = var ?? var2;
 */
class BinaryAndIssetVisitor extends BaseVisitor
{
    public function enterNode(Node $node)
    {
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
                    $newExp = new Node\Expr\BinaryOp\Coalesce($expr->expr, $expr->var);
                    $newAssign = new Assign($expr->var, $newExp);
                    $node->setAttribute('replace', new Expression($newAssign));
                }
            }
        }
    }

    public function leaveNode(Node $node)
    {
        /**
         *  Бинарные операции (математика, логика, конкатенация)
         *  Process on leave (bottom-up) so nested operations are calculated first
         */
        if ($node instanceof Node\Expr\BinaryOp) {
            if ($node->right instanceof Node\Expr\ConstFetch) {
                $node->right = $this->booleanBinaryTyping($node, $node->right);
            }
            if ($node->left instanceof Node\Expr\ConstFetch) {
                $node->left = $this->booleanBinaryTyping($node, $node->left);
            }

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
                    $newNode = new Node\Scalar\String_( $node->left->value . $node->right->value );
                    return $newNode;
                } else {
                    throw new \Exception('New BinaryOp! : ' . get_class($node));
                }
                return is_int($calc) ? new Node\Scalar\LNumber($calc) : new Node\Scalar\DNumber($calc);
            }
        }

        // Call parent's leaveNode to handle 'remove' and 'replace' attributes
        return parent::leaveNode($node);
    }

    protected function booleanBinaryTyping($expression, $bool)
    {
        // In php-parser 5.x, use toString() method
        $name = strtolower($bool->name->toString());
        if ($expression instanceof Node\Expr\BinaryOp\Concat) {
            if ($name === 'true') {
                return new Node\Scalar\String_('1');
            }
            if ($name === 'false') {
                return new Node\Scalar\String_('');
            }
        // math ?
        } else {
            if ($name === 'true') {
                return new Node\Scalar\LNumber(1);
            }
            if ($name === 'false') {
                return new Node\Scalar\LNumber(0);
            }
        }
        // Return original value if no transformation is needed
        return $bool;
    }
}