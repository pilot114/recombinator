<?php

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;

/**
 * - несколько echo заменяем на конкатенацию
 *
 * TODO: доделать, когда решится проблема с next и будет выполнение функций/классов
 * - если переменная присваивается 1 раз - можно удалить, если не глобальная и ее составляющий не модифицируются
 * - если переменная используется 1 раз - можно подставить даже не скаляр
 */
class ConcatAssertVisitor extends BaseVisitor
{
    protected $echoStack = [];

    protected $singleAsserts = [];

    public function enterNode(Node $node)
    {
        if ($node instanceof Node\Stmt\Echo_) {
            $this->echoStack = array_merge($this->echoStack, $node->exprs);
            if ($node->getAttribute('next') instanceof Node\Stmt\Echo_) {
                $node->setAttribute('remove', true);
            } else {
                $commonEcho = array_shift($this->echoStack);
                foreach ($this->echoStack as $echo) {
                    $commonEcho = new Node\Expr\BinaryOp\Concat($commonEcho, $echo);
                }

                $this->echoStack = [];
                return new Node\Stmt\Echo_([$commonEcho]);
            }
        }

        return null;
    }
}
