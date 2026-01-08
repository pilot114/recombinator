<?php

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;
use PhpParser\Node\Name;

/**
 * Выполняет стандартные детерминированные функции (без побочных эффектов)
 * Критерий - если при заданных аргументах всегда будет одинаковый результат
 *
 * Выжный момент - некоторые функции дают детерминированный результат даже
 * на переменных (например, is_array)
 */
class EvalStandardFunction extends BaseVisitor
{
    /**
     * Гарантированно проходят со статичными аргументами
     */
    protected $functionListNeedStatic = [
        'in_array',
    ];
    /**
     * Требуют специальной проверки аргументов (например, на тип)
     */
    protected $specialFunction = [
        'is_array' => 'isArrayHandler'
    ];

    public function enterNode(Node $node)
    {
        if ($node instanceof Node\Expr\FuncCall) {
            // In php-parser 5.x, use toString() method
            if ($node->name instanceof Node\Name) {
                $funcName = $node->name->toString();

                // Check if function name contains namespace separator
                // (simple function names don't have backslash)
                if (strpos($funcName, '\\') === false) {
                    if (in_array($funcName, $this->functionListNeedStatic) && $this->staticArgs($node)) {
                        // eval
                    }
                    if (in_array($funcName, array_keys($this->specialFunction))) {
                        $handlerName = $this->specialFunction[$funcName];
                        if (method_exists($this, $handlerName)) {
                            return $this->{$handlerName}($node);
                        }
                    }
                }
            }
        }
    }

    protected function isArrayHandler(Node\Expr\FuncCall $node)
    {
        if ($node->args[0]->value instanceof Node\Expr\Array_) {
            return new Name('true');
        }
        return new Name('false');
    }

    /**
     * проверяем что аргументы скаляры или предопределенные массивы
     */
    protected function staticArgs(Node $node)
    {
        foreach ($node->args as $arg) {
            if (
                !$arg->value instanceof Node\Scalar
                && !$arg->value instanceof Node\Expr\ConstFetch
            ) {
                if (!$arg->value instanceof Node\Expr\Array_) {
                    return false;
                } else {
                    foreach ($arg->value->items as $item) {
                        if (
                            !$item->value instanceof Node\Scalar
                            && !$item->value instanceof Node\Expr\ConstFetch
                        ) {
                            return false;
                        }
                    }
                }
            }
        }
        return true;
    }
}
