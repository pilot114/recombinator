<?php

namespace Recombinator\Visitor;

use PhpParser\Node;

/**
 * TODO: доделать, когда решится проблема с next и будет выполнение функций/классов
 * - несколько echo заменяем на конкатенацию
 * - если переменная присваивается 1 раз - можно удалить, если не глобальная и ее составляющий не модифицируются
 * - если переменная используется 1 раз - можно подставить даже не скаляр
 */
class ConcatAssertVisitor extends BaseVisitor
{
    protected $echoStack = [];
    protected $singleAsserts = [];
}