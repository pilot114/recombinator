<?php

namespace Recombinator\Visitor;

use PhpParser\Node;
use Recombinator\ScopeStore;

/**
 * Подмена переменных их значениями (скаляры)
 */
class VarToScalarVisitor extends BaseVisitor
{
    protected $scopeStore;

    public function __construct(ScopeStore $scopeStore)
    {
        $this->scopeStore = $scopeStore;
        $this->scopeStore->currentScope = $this->scopeName;
    }

    public function enterNode(Node $node)
    {
        /**
         * Замена переменных 1/3 - Запись скаляра
         * Переменная "кэширует" значение, поэтому чтобы избежать сайд-эффектов
         * подменяем только переменные со скалярным значением
         */
        if (isset($node->expr) && $node->expr instanceof Node\Expr\Assign) {
            $assign = $node->expr;
            $varName = $assign->var->name;
            if ($assign->expr instanceof Node\Scalar) {
                // заносим в кеш
                $varExprScalar = clone $assign->expr;
                $varExprScalar->setAttributes([]);
                $this->scopeStore->setVarToScope($varName, $varExprScalar);
                $node->setAttribute('remove', true);
            }
        }

        /**
         * Замена переменных 2/3 - чтение переменной
         * Если переменная читается и есть в кеше скаляров - можно смело заменять
         */
        if ($node instanceof Node\Expr\Variable) {
            $varName = $node->name;

            $isRead = $this->isVarRead($node, $varName);
            if ($isRead && $this->scopeStore->getVarFromScope($varName)) {
                $node->setAttribute('replace', $this->scopeStore->getVarFromScope($varName));
            }
        }

        /**
         * Замена переменных 3/3 - Запись нескаляра
         * Очищаем замену из кеша, если модифицируем переменную не скаляром
         */
        if (isset($node->expr) && $node->expr instanceof Node\Expr\Assign) {
            $assign = $node->expr;
            $varName = $assign->var->name;
            if (!$assign->expr instanceof Node\Scalar) {
                if ($this->scopeStore->getVarFromScope($varName)) {
                    // Если внутри присвоения читается эта переменная - сначала пробуем её подменить
                    $innerVars = $this->findNode(Node\Expr\Variable::class, $assign);
                    foreach ($innerVars as $innerVar) {
                        $isRead = $this->isVarRead($innerVar, $innerVar->name);
                        if ($isRead && $this->scopeStore->getVarFromScope($innerVar->name)) {
                            $innerVar->setAttribute('replace', $this->scopeStore->getVarFromScope($innerVar->name));
                        }
                    }

                    $this->scopeStore->removeVarFromScope($varName);
                }
            }
        }
    }

    /**
     * Проверяем, что это чтение переменной
     */
    protected function isVarRead(Node $node, $varName)
    {
        $parent = $node->getAttribute('parent');
        if ($parent instanceof Node\Expr\Assign && $parent->var->name === $varName) {
            return false;
        }
        return true;
    }
}