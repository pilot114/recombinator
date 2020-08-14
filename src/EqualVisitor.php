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
    protected $modifyCount = 0;


    public function beforeTraverse(array $nodes)
    {
        $this->ast = $nodes;
//        $this->printNodesPos(Node\Expr\Variable::class);
        echo "*** startTraverse ***\n";
    }
    public function afterTraverse(array $nodes)
    {
        echo "*** endTraverse ***\n\n";
    }

    public function enterNode(Node $node)
    {
        // TODO includes и определения надо обрабатывать как отдельный скоп, пока просто вырезаем
        if (
            $node instanceof Function_ ||
            $node instanceof Node\Stmt\Class_ ||
            isset($node->expr) && $node->expr instanceof Node\Expr\Include_ ||
            $node instanceof Node\Stmt\InlineHTML
        ) {
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
        if ($node->getAttribute('remove')) {
            return NodeTraverser::REMOVE_NODE;
        }
        if ($node->getAttribute('replace')) {
            return $node->getAttribute('replace');
        }

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

    /**
     * напечатать AST и и соответствующий ему код
     */
    protected function debug(Node $stmt, $onlyClass = false)
    {
        // удаленный узел - у него нет позиции
        if ($stmt->getStartLine() < 0) {
            echo sprintf("%s\n", get_class($stmt));
        } else {
            echo sprintf(
                "%s %s:%s-%s\n", get_class($stmt),
                $stmt->getStartLine(), $stmt->getStartFilePos(), $stmt->getEndFilePos()
            );
        }
        if ($onlyClass) return;
        echo (new PrettyDumper())->dump($stmt) . "\n";
        echo (new StandardPrinter)->prettyPrint([$stmt]) . "\n";
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