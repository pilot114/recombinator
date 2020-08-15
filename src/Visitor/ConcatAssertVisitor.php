<?php

namespace Recombinator\Visitor;

use PhpParser\Node;

/**
 * - несколько echo заменяем на конкатенацию
 * - если переменная встречается 1 раз и это присваивание - можно удалить, если не глобальная
 * - если переменная встречается 2 раз - можно подставить даже не скаляр
 */
class ConcatAssertVisitor extends BaseVisitor
{
    protected $echoStack = [];

    public function enterNode(Node $node)
    {
        if ($node instanceof Node\Stmt\Echo_) {
            var_dump($node->getAttribute('next'));

            if ($prev = $node->getAttribute('previous')) {
                if ($prev instanceof Node\Stmt\Echo_) {
                    $this->debug($prev);
                    $prev->setAttribute('remove', true);
//                    $node->setAttribute('replace');
                }
            }
        }
    }
}