<?php

namespace Recombinator\Visitor;

use PhpParser\Node;
use Recombinator\ScopeStore;

/**
 * Замена тела функции на однострочное выражение.
 * Переменным, являющимся аргументами, выставляется аттрибуты 'arg_index' и 'arg_default'
 *
 * Если такая замена уже выполнена, отправляем выражение в хранилище контекстов (scopeStore) и удаляем файл
 */
class FunctionScopeVisitor extends BaseVisitor
{
    protected $isGlobalScope;
    protected $scopeStore;
    protected $cacheDir;

    public function __construct(ScopeStore $scopeStore, $cacheDir)
    {
        $this->scopeStore = $scopeStore;

        $this->cacheDir = $cacheDir;
    }

    public function beforeTraverse(array $nodes)
    {
        parent::beforeTraverse($nodes);
        $this->isGlobalScope = count($nodes) !== 1;
    }

    public function enterNode(Node $node)
    {
        if ($this->isGlobalScope) return null;

        // скоп функции
        if ($node instanceof Node\Stmt\Function_) {
            // тело оптимизировано, можно заменять
            if (count($node->stmts) === 1 && $node->stmts[0] instanceof Node\Stmt\Return_) {
                $node = $this->markupFunctionBody($node);
                $return = $node->stmts[0];

                $node->setAttribute('remove', true);
                // кладем однострочник в стор, чтобы использовать в CallFunctionVisitor
                $this->scopeStore->setFunctionToGlobal(
                    $node->name->name,
                    clone $return->expr
                );
                // и удаляем файл
                $fileName = $this->cacheDir . '/' . $this->scopeName;
                // в wsl2 почему-то не удаляется...
                echo unlink($fileName);
            }
        }
    }
}