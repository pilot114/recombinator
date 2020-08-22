<?php

namespace Recombinator\Visitor;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use Recombinator\ScopeStore;

/**
 * Замена вызова функции телом функции
 */
class CallFunctionVisitor extends BaseVisitor
{
    protected $scopeStore;

    public function __construct(ScopeStore $scopeStore)
    {
        $this->scopeStore = $scopeStore;
    }

    public function enterNode(Node $node)
    {
        if ($node instanceof Node\Expr\FuncCall) {
            $keys = array_keys($this->scopeStore->functions);
            foreach ($keys as $key) {
                // находим в сторе тело функции
                $parts = $node->name->parts;
                if (strpos($key, 'Function_' . array_shift($parts) . '_') === 0) {

                    // заменяем в копии тела параметры на аргументы
                    $expr = clone $this->scopeStore->functions[$key];
                    $traverser = new NodeTraverser();
                    $traverser->addVisitor(new class($node) extends BaseVisitor {
                        protected $node;
                        public function __construct($node)
                        {
                            $this->node = $node;
                        }
                        public function enterNode(Node $var)
                        {
                            if ($var instanceof Node\Expr\Variable) {
                                $i = $var->getAttribute('arg_index');
                                if (isset($this->node->args[$i])) {
                                    return $this->node->args[$i]->value;
                                } else {
                                    return $var->getAttribute('arg_default');
                                }
                            }
                        }
                    });
                    $expr = $traverser->traverse([$expr]);
                    return $expr[0];
                }
            }
        }
    }
}