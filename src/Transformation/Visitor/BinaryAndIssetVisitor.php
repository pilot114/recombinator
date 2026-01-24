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
    public function enterNode(Node $node): int|Node|array|null
    {
        /**
         * if (isset(var)) {
         *     var2 = var;
         * }
         * =>
         * var2 = var ?? var2;
         */
        if ($node instanceof Node\Stmt\If_ && ($node->cond instanceof Node\Expr\Isset_ && count($node->stmts) === 1)) {
            $firstStmt = $node->stmts[0];
            if ($firstStmt instanceof Expression) {
                $expr = $firstStmt->expr;
                if ($expr instanceof Assign) {
                    $newExp = new Node\Expr\BinaryOp\Coalesce($expr->expr, $expr->var);
                    $newAssign = new Assign($expr->var, $newExp);
                    $node->setAttribute('replace', new Expression($newAssign));
                }
            }
        }

        return null;
    }

    #[\Override]
    public function leaveNode(Node $node): int|\PhpParser\Node|array|null
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

            $left = $node->left;
            $right = $node->right;

            // Check if both operands are numeric scalars for math operations
            $leftIsNumeric = $left instanceof Node\Scalar\LNumber || $left instanceof Node\Scalar\DNumber;
            $rightIsNumeric = $right instanceof Node\Scalar\LNumber || $right instanceof Node\Scalar\DNumber;

            // For concatenation, we accept strings too
            if ($node instanceof Node\Expr\BinaryOp\Concat) {
                $leftIsScalar = $left instanceof Node\Scalar\LNumber
                    || $left instanceof Node\Scalar\DNumber
                    || $left instanceof Node\Scalar\String_;
                $rightIsScalar = $right instanceof Node\Scalar\LNumber
                    || $right instanceof Node\Scalar\DNumber
                    || $right instanceof Node\Scalar\String_;
                if ($leftIsScalar && $rightIsScalar) {
                    /**
 * @var int|float|string $leftValue
*/
                    $leftValue = $left->value;
                    /**
 * @var int|float|string $rightValue
*/
                    $rightValue = $right->value;
                    return new Node\Scalar\String_($leftValue . $rightValue);
                }
            }

            // For math operations, both must be numeric
            if ($leftIsNumeric && $rightIsNumeric) {
                /**
 * @var int|float $leftValue
*/
                $leftValue = $left->value;
                /**
 * @var int|float $rightValue
*/
                $rightValue = $right->value;

                if ($node instanceof Node\Expr\BinaryOp\Plus) {
                    $calc = $leftValue + $rightValue;
                } elseif ($node instanceof Node\Expr\BinaryOp\Minus) {
                    $calc = $leftValue - $rightValue;
                } elseif ($node instanceof Node\Expr\BinaryOp\Mul) {
                    $calc = $leftValue * $rightValue;
                } elseif ($node instanceof Node\Expr\BinaryOp\Div) {
                    $calc = $leftValue / $rightValue;
                } else {
                    // Unknown operation, skip transformation
                    return parent::leaveNode($node);
                }

                return is_int($calc) ? new Node\Scalar\LNumber($calc) : new Node\Scalar\DNumber((float) $calc);
            }
        }

        // Call parent's leaveNode to handle 'remove' and 'replace' attributes
        return parent::leaveNode($node);
    }

    protected function booleanBinaryTyping(Node\Expr\BinaryOp $expression, Node\Expr\ConstFetch $bool): Node\Expr
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
