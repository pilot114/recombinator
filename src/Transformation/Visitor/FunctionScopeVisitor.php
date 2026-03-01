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
#[VisitorMeta('Замена тела функции на однострочник → ScopeStore (работает пофайлово)')]
class FunctionScopeVisitor extends BaseVisitor
{
    protected bool $isGlobalScope = false;


    protected ScopeStore $scopeStore;

    public function __construct(?ScopeStore $scopeStore = null, protected string $cacheDir = '')
    {
        $this->scopeStore = $scopeStore ?? ScopeStore::default();
        if ($this->cacheDir === '') {
            $this->cacheDir = sys_get_temp_dir() . '/recombinator';
        }
    }

    #[\Override]
    public function beforeTraverse(array $nodes): ?array
    {
        parent::beforeTraverse($nodes);
        $this->isGlobalScope = count($nodes) !== 1;
        return null;
    }

    public function enterNode(Node $node)
    {
        if ($this->isGlobalScope) {
            return null;
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
