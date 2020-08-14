<?php

namespace Recombinator;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Function_;
use PhpParser\NodeDumper;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;

/**
 * Выполняет эквивалентные замены выражений
 */
class EqualVisitor extends NodeVisitorAbstract
{
    protected $scopeVarsReplace = [];
    protected $ast;

    public function beforeTraverse(array $nodes)
    {
        echo "*** beforeTraverse ***\n\n";
        $this->ast = $nodes;
        $this->printNodesPos(Node\Expr\Variable::class);
    }
    public function afterTraverse(array $nodes)
    {
        echo "*** afterTraverse ***\n\n";
    }

    public function enterNode(Node $node)
    {
        // TODO includes и определения надо отправлять на выполнение, пока просто вырезаем
        if (
            $node instanceof Function_ ||
            $node instanceof Node\Stmt\Class_ ||
            isset($node->expr) && $node->expr instanceof Node\Expr\Include_ ||
            $node instanceof Node\Stmt\InlineHTML
        ) {
            $node->setAttribute('remove', true);
            return NodeTraverser::DONT_TRAVERSE_CHILDREN;
        }

        if (isset($node->expr) && $node->expr instanceof Node\Expr\Assign) {
            $assign = $node->expr;

            /**
             * Инициализация переменной (запись)
             * Переменная "кэширует" значение, поэтому чтобы избежать сайд-эффектов
             * подменяем только переменные со скалярным значением
             */
            $varName = $assign->var->name;
            if ($assign->expr instanceof Node\Scalar) {
                // заносим в кеш
                $varExprScalar = $assign->expr;
                $varExprScalar->setAttributes([]);
                $this->scopeVarsReplace[$varName] = $varExprScalar;

                $node->setAttribute('remove', true);
                var_dump('set to cache ' . $varName);
                return null;
            } else {
                /**
                 * Если значение не скалярное - удаляем из кеша
                 */
                if (isset($this->scopeVarsReplace[$varName])) {
                    var_dump('delete from cache ' . $varName);
                    unset($this->scopeVarsReplace[$varName]);
                }
            }
        }
    }

    public function leaveNode(Node $node)
    {
        if ($node->getAttribute('remove')) {
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

//
//        /**
//         * Подмена чтения переменной
//         */
//        if ($node instanceof Node\Expr\Variable) {
//            $parent = $node->getAttribute('parent');
//            /**
//             * Если проверяемая переменная перезаписывается - проходим мимо
//             */
//            if ($parent instanceof Node\Expr\Assign && $parent->var->name === $node->name) {
//            } else {
//                if (isset($this->scopeVarsReplace[$node->name])) {
//                    $GLOBALS['modify_count']++;
//                    var_dump('usage ' . $node->name);
//                    return $this->scopeVarsReplace[$node->name];
//                }
//            }
//        }

        return null;

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
     * напечатать AST и и соответствующий ему код
     */
    protected function debug($stmt, $onlyClass = false)
    {
        echo get_class($stmt) . "\n";
        if ($onlyClass) return;
        echo (new PrettyDumper())->dump($stmt) . "\n";
        echo (new StandardPrinter)->prettyPrint([$stmt]) . "\n\n";
    }

    /**
     * упрощенный способ найти узлы определенного типа
     *
     * Пример:
     * $assigns = $p->findNode(Assign::class);
     * $vars = array_map(function($x) { return $x->var->name; }, $assigns);
     */
    protected function findNode($className)
    {
        $nodeFinder = new NodeFinder();
        return $nodeFinder->findInstanceOf($this->ast, $className);
    }

    /**
     * Выводит список нод с их позициями (строка:смещение в файле)
     */
    protected function printNodesPos($className)
    {
        $nodes = $this->findNode($className);

        $maxLenToken = 0;
        foreach ($nodes as $node) {
            $lenToken = strlen((new StandardPrinter)->prettyPrint([$node]));
            if ($maxLenToken < $lenToken) {
                $maxLenToken = $lenToken;
            }
            $node->setAttribute('lenToken', $lenToken);
        }
        echo $className . " count: " . count($nodes) . "\n";
        foreach ($nodes as $node) {
            echo sprintf(
                "%s%s%s:%s-%s\n",
                (new StandardPrinter)->prettyPrint([$node]),
                str_repeat(' ', ($maxLenToken+3) - $node->getAttribute('lenToken')),
                $node->getAttribute('startLine'),
                $node->getAttribute('startFilePos'),
                $node->getAttribute('endFilePos')
            );
        }
        echo "\n";
    }
}