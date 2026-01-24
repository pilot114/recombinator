<?php

namespace Recombinator\Transformation;

use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Return_;

/**
 * Результат извлечения блока кода в функцию
 *
 * Содержит новую функцию, вызов этой функции для замены исходного блока,
 * а также информацию о параметрах и возвращаемом значении. Используется
 * FunctionExtractor для представления результата извлечения.
 */
class ExtractionResult
{
    public function __construct(
        public readonly Function_ $function,
        public readonly Expression|Return_|FuncCall $call,
        public readonly string $functionName,
        public readonly array $parameters,
        public readonly ?string $returnVariable,
    ) {
    }

    /**
     * Возвращает количество параметров функции
     */
    public function getParameterCount(): int
    {
        return count($this->parameters);
    }

    /**
     * Проверяет, возвращает ли функция значение
     */
    public function hasReturn(): bool
    {
        return $this->returnVariable !== null;
    }
}
