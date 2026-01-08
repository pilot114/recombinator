<?php

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;
use Recombinator\Domain\ScopeStore;

/**
 * Подмена переменных их значениями (скаляры)
 */
class VarToScalarVisitor extends BaseVisitor
{
    public $scopeStore;
    /**
     * TODO не всегда возможно удалить
     * $test = 'abc';
     * if ($b) {
     *    $test = $b;
     * }
     * тут удалить скаляр нельзя
     */
    public $maybeRemove;

    public function __construct(ScopeStore $scopeStore)
    {
        $this->scopeStore = $scopeStore;
    }

    public function enterNode(Node $node)
    {
        /**
         * Замена переменных 1/3 - Запись скаляра
         * Переменная "кэширует" значение, поэтому чтобы избежать сайд-эффектов
         * подменяем только переменные со скалярным значением
         */
        if ($node instanceof Node\Expr\Assign) {
            $varName = $node->var->name;
            if ($node->expr instanceof Node\Scalar) {
                // заносим в кеш
                $varExprScalar = clone $node->expr;
                $varExprScalar->setAttributes([]);
                $this->scopeStore->setVarToScope($varName, $varExprScalar);

                $parent = $node->getAttribute('parent');
                if ($parent !== null) {
                    $parent->setAttribute('remove', true);
                }
            }
        }

        /**
         * Замена переменных 2/3 - чтение переменной
         * Если переменная читается и есть в кеше скаляров - обновляем
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
                        if ($innerVar->name !== $varName) continue;

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