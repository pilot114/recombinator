<?php

declare(strict_types=1);

namespace Recombinator\Transformation;

use PhpParser\Node;
use PhpParser\BuilderFactory;
use PhpParser\Comment\Doc;
use Recombinator\Domain\FunctionCandidate;
use Recombinator\Domain\SideEffectType;

/**
 * Извлечение блоков кода в функции
 *
 * Создает новые функции на основе кандидатов (FunctionCandidate)
 * и заменяет исходные блоки кода вызовами этих функций.
 *
 * Реализует правила:
 * - FOLD-FUNC-1: Создание функции для чистого блока
 * - FOLD-FUNC-2: Создание функции для блока с эффектами
 *
 * Пример использования:
 * ```php
 * $extractor = new FunctionExtractor();
 * $result = $extractor->extract($candidate, $ast);
 *
 * // $result содержит:
 * // - новую функцию (Node\Stmt\Function_)
 * // - вызов функции (Node\Expr\FuncCall)
 * // - обновленный AST
 * ```
 */
class FunctionExtractor
{
    private readonly BuilderFactory $factory;

    public function __construct()
    {
        $this->factory = new BuilderFactory();
    }

    /**
     * Извлекает блок кода в функцию
     *
     * @param  FunctionCandidate $candidate    Кандидат на
     *                                         извлечение
     * @param  string|null       $functionName Имя функции (если null,
     *                                         будет сгенерировано)
     * @return ExtractionResult Результат извлечения
     */
    public function extract(
        FunctionCandidate $candidate,
        ?string $functionName = null
    ): ExtractionResult {
        // Генерируем имя функции
        $functionName ??= $candidate->suggestFunctionName();

        // Создаем функцию
        $function = $this->createFunction($candidate, $functionName);

        // Создаем вызов функции
        $call = $this->createFunctionCall($candidate, $functionName);

        return new ExtractionResult(
            function: $function,
            call: $call,
            functionName: $functionName,
            parameters: $candidate->getFunctionParameters(),
            returnVariable: $candidate->returnVariable,
        );
    }

    /**
     * Создает объявление функции
     */
    private function createFunction(
        FunctionCandidate $candidate,
        string $functionName
    ): Node\Stmt\Function_ {
        $builder = $this->factory->function($functionName);

        // Добавляем параметры
        foreach ($candidate->getFunctionParameters() as $param) {
            $paramName = ltrim((string) $param, '$');
            $builder->addParam(
                $this->factory->param($paramName)
            );
        }

        // Добавляем тело функции
        foreach ($candidate->nodes as $node) {
            $builder->addStmt($node);
        }

        // Если есть возвращаемая переменная, добавляем return
        if ($candidate->returnVariable) {
            $returnVar = ltrim($candidate->returnVariable, '$');
            $builder->addStmt(
                new Node\Stmt\Return_(
                    new Node\Expr\Variable($returnVar)
                )
            );
        }

        // Добавляем docblock
        $docComment = $this->generateDocComment($candidate);
        $function = $builder->getNode();
        $function->setAttribute('comments', [new Doc($docComment)]);

        return $function;
    }

    /**
     * Создает вызов функции
     */
    private function createFunctionCall(
        FunctionCandidate $candidate,
        string $functionName
    ): \PhpParser\Node\Stmt\Expression {
        // Создаем аргументы вызова
        $args = [];
        foreach ($candidate->getFunctionParameters() as $param) {
            $paramName = ltrim((string) $param, '$');
            $args[] = new Node\Arg(
                new Node\Expr\Variable($paramName)
            );
        }

        // Создаем вызов функции
        $funcCall = new Node\Expr\FuncCall(
            new Node\Name($functionName),
            $args
        );

        // Если есть возвращаемая переменная, оборачиваем в присваивание
        if ($candidate->returnVariable) {
            $returnVar = ltrim($candidate->returnVariable, '$');
            return new Node\Stmt\Expression(
                new Node\Expr\Assign(
                    new Node\Expr\Variable($returnVar),
                    $funcCall
                )
            );
        }

        // Иначе просто вызов
        return new Node\Stmt\Expression($funcCall);
    }

    /**
     * Генерирует PHPDoc комментарий для функции
     */
    private function generateDocComment(FunctionCandidate $candidate): string
    {
        $lines = [];
        $lines[] = '/**';

        // Описание функции
        $description = match ($candidate->effectType) {
            SideEffectType::PURE => 'Pure computation (no side effects)',
            SideEffectType::IO => 'I/O operations',
            SideEffectType::EXTERNAL_STATE => 'External state access',
            SideEffectType::DATABASE => 'Database operations',
            SideEffectType::HTTP => 'HTTP requests',
            SideEffectType::NON_DETERMINISTIC => 'Non-deterministic operations',
            default => 'Extracted function',
        };
        $lines[] = ' * ' . $description;
        $lines[] = ' *';

        // Параметры
        foreach ($candidate->getFunctionParameters() as $param) {
            $paramName = ltrim((string) $param, '$');
            $lines[] = ' * @param mixed $' . $paramName;
        }

        // Возвращаемое значение
        if ($candidate->returnVariable) {
            $lines[] = ' * @return mixed';
        }

        // Метаданные
        $lines[] = ' *';
        $lines[] = ' * Generated by Recombinator';
        $lines[] = ' * Complexity: ' . $candidate->complexity;
        $lines[] = ' * Effect type: ' . $candidate->effectType->value;
        $lines[] = ' * Original lines: ' . $candidate->startLine . '-' . $candidate->endLine;

        $lines[] = ' */';

        return implode("\n", $lines);
    }
}
