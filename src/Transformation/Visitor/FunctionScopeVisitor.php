<?php

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;
use Recombinator\Domain\ScopeStore;

/**
 * Замена тела функции на однострочное выражение.
 * Переменным, являющимся аргументами, выставляется аттрибуты 'arg_index' и 'arg_default'
 *
 * Если такая замена уже выполнена, отправляем выражение в хранилище контекстов (scopeStore) и удаляем файл
 */
class FunctionScopeVisitor extends BaseVisitor
{
    /**
     * @var mixed 
     */
    protected $isGlobalScope;


    /**
     * @param mixed $cacheDir
     */
    public function __construct(protected \Recombinator\Domain\ScopeStore $scopeStore, protected $cacheDir)
    {
    }

    #[\Override]
    public function beforeTraverse(array $nodes): void
    {
        parent::beforeTraverse($nodes);
        $this->isGlobalScope = count($nodes) !== 1;
    }

    public function enterNode(Node $node)
    {
        if ($this->isGlobalScope) { return null;
        }

        // скоп функции
        // тело оптимизировано, можно заменять
        if ($node instanceof Node\Stmt\Function_ && (count($node->stmts) === 1 && $node->stmts[0] instanceof Node\Stmt\Return_)) {
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

        return null;
    }
}
