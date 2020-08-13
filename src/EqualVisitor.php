<?php

namespace Recombinator;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Function_;
use PhpParser\NodeDumper;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;

/**
 * Выполняет эквивалентные замены выражений
 */
class EqualVisitor extends NodeVisitorAbstract
{
    protected $scopeVarsReplace = [];

    public function enterNode(Node $node)
    {
        // TODO includes и определения отправляем на выполнение
//        var_dump('enterNode: ' . get_class($node));

//                if ($node instanceof Include_) {
//                    // включить в файл, предварительно рекурсивно обработав
//                }
//                if ($node instanceof Function_) {
//                    echo (new NodeDumper())->dump($node) . "\n";die();

//                    $fVisitor = new FunctionVisitor();
//                    $this->buffer[$node->name] = $fVisitor->enterNode($node);
//                }
    }

    public function leaveNode(Node $node)
    {
        // не PHP, объявления классов и функций вырезаем
        if (
            $node instanceof Function_ ||
            $node instanceof Node\Stmt\InlineHTML ||
            $node instanceof Node\Stmt\Class_ ||
            false
        ) {
//            var_dump(get_class($node));
            return NodeTraverser::REMOVE_NODE;
        }

        // инклюды вырезаем
        if (isset($node->expr) && $node->expr instanceof Node\Expr\Include_) {
            return NodeTraverser::REMOVE_NODE;
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
                } else {
                    throw new \Exception('New BinaryOp! : ' . get_class($node));
                }
                $GLOBALS['modify_count']++;
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
                    $GLOBALS['modify_count']++;
                    $newExp = new Node\Expr\BinaryOp\Coalesce($expr->expr, $expr->var);
                    $newAssign = new Assign($expr->var, $newExp);
                    return new Expression($newAssign);
                }
            }
        }

        /**
         * Если переменной присваивается скаляр - дальше по скопу любое
         * чтение переменной заменяем на скаляр
         */
        if ($node instanceof Node\Expr\Assign) {
            $this->debug($node);
//            $varName = $node->var->name;
//            $varExpr = $node->expr;
//            $varExpr->setAttributes([]);
//            $this->scopeVarsReplace[$varName] = $varExpr;
//            $GLOBALS['modify_count']++;
//            return NodeTraverser::REMOVE_NODE;
        }
        return null;

        if ($node instanceof Node\Expr\Variable) {
            if (isset($this->scopeVarsReplace[$node->name])) {
//                $GLOBALS['modify_count']++;

                $parent = $node->getAttribute('parent');
                // если присваивание - нужно обновить значение переменной
                if ($parent instanceof Node\Expr\Assign) {
                    // TODO
                    // $username = $_GET['username'] ?? 'default_username';
                    // =>
                    // $username = ($_GET['username'] ?? 'default_username');
//                    $this->debug([$parent]);
//                    return $this->scopeVarsReplace[$node->name] = $parent->expr;
                // если не присваивание (т.е. не запись) - можно заменить на значение
                } else {
                    return $this->scopeVarsReplace[$node->name];
                }
            }
        }

        /**
         * TODO:
         * Если все аргументы функции известны и нет побочных эффектов - заменяем выполнением
         * Если побочки есть - заменяем на тело из буфера
         */
//        if ($node instanceof FuncCall) {
//            if (array_key_exists($node->name->getLast(), $this->buffer)) {
//                return $this->buffer[$node->name->getLast()];
//            }
//        }
    }

    /**
     * напечатать AST и и соотвествующий ему код
     */
    protected function debug($stmt)
    {
        echo get_class($stmt) . "\n";
        echo (new PrettyDumper())->dump($stmt) . "\n";
        echo (new StandardPrinter)->prettyPrint([$stmt]) . "\n\n";
    }
}