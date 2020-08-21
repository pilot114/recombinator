<?php

namespace Recombinator\Visitor;

use PhpParser\Node;

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

    public function __construct($scopeStore, $cacheDir)
    {
        $this->scopeStore = $scopeStore;
        $this->scopeStore->currentScope = $this->scopeName;

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
                $return = $node->stmts[0];
                $params = [];
                foreach ($node->params as $i => $param) {
                    $params[$param->var->name] = [
                        'index' => $i,
                        'default' => $param->default->value ?? null,
                    ];
                }
                $vars = $this->findNode(Node\Expr\Variable::class, $return->expr);
                foreach ($vars as $var) {
                    if (isset($params[$var->name])) {
                        $var->setAttribute('arg_index', $params[$var->name]['index']);
                        $var->setAttribute('arg_default', $params[$var->name]['default']);
                    }
                }
                $node->setAttribute('remove', true);
                // кладем однострочник в стор, чтобы использовать в CallFunctionVisitor
                $this->scopeStore->functions[$this->scopeName] = clone $return->expr;
                // и удаляем файл
                $fileName = $this->cacheDir . '/' . $this->scopeName;
                // в wsl2 почему-то не удаляется...
                echo unlink($fileName);
            }
        }
    }
}