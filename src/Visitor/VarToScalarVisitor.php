<?php

namespace Recombinator\Visitor;

use PhpParser\Node;
use PhpParser\Node\Stmt\Function_;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;

/**
 * Подмена переменных их значениями (скаляры)
 */
class VarToScalarVisitor extends BaseVisitor
{
    public function enterNode(Node $node)
    {
        /**
         * Замена переменных 1/3 - Запись скаляра
         * Переменная "кэширует" значение, поэтому чтобы избежать сайд-эффектов
         * подменяем только переменные со скалярным значением
         */
//        if (isset($node->expr) && $node->expr instanceof Assign) {
//            $assign = $node->expr;
//            $varName = $assign->var->name;
//            if ($assign->expr instanceof Node\Scalar) {
//                // заносим в кеш
//                $varExprScalar = $assign->expr;
//                $varExprScalar->setAttributes([]);
//                $this->scopeVarsReplace[$varName] = $varExprScalar;
//                $node->setAttribute('remove', true);
//            }
//        }

        /**
         * Замена переменных 2/3 - чтение переменной
         * Если переменная читается и есть в кеше скаляров - можно смело заменять
         */
//        if ($node instanceof Node\Expr\Variable) {
//            $varName = $node->name;
//
//            $isWrite = false;
//            $parent = $node->getAttribute('parent');
//            if ($parent instanceof Assign && $parent->var->name === $varName) {
//                $isWrite = true;
//            }
//
//            if (!$isWrite && isset($this->scopeVarsReplace[$varName])) {
//                $node->setAttribute('replace', $this->scopeVarsReplace[$varName]);
//            }
//        }

        /**
         * Замена переменных 3/3 - Запись нескаляра
         * Очищаем замену из кеша, если модифицируем переменную не скаляром
         */
//        if (isset($node->expr) && $node->expr instanceof Assign) {
//            $assign = $node->expr;
//            $varName = $assign->var->name;
//            if (!$assign->expr instanceof Node\Scalar) {
//                if (isset($this->scopeVarsReplace[$varName])) {
//                    unset($this->scopeVarsReplace[$varName]);
//                }
//            }
//        }
    }
}