<?php

namespace Recombinator\Analysis;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * Visitor для анализа использования переменных в коде
 *
 * Собирает информацию об используемых и определенных переменных в блоке кода,
 * игнорируя вложенные функции и замыкания. Используется для определения
 * параметров при извлечении функций и анализа зависимостей между участками кода.
 */
class VariableVisitor extends NodeVisitorAbstract
{
    /**
     * Используемые переменные
     *
     * @var array<string>
     */
    private array $used = [];

    /**
     * Определенные переменные
     *
     * @var array<string>
     */
    private array $defined = [];

    /**
     * Контекст вложенности (функции, замыкания)
     */
    private int $depth = 0;

    public function enterNode(Node $node): int|Node|array|null
    {
        // Пропускаем вложенные функции и замыкания
        if ($node instanceof Node\Stmt\Function_
            || $node instanceof Node\Stmt\ClassMethod
            || $node instanceof Node\Expr\Closure
            || $node instanceof Node\Expr\ArrowFunction
        ) {
            $this->depth++;
            return null;
        }

        // Игнорируем переменные внутри вложенных функций
        if ($this->depth > 0) {
            return null;
        }

        // Определение переменной (присваивание)
        if ($node instanceof Node\Expr\Assign) {
            $var = $node->var;
            if ($var instanceof Node\Expr\Variable && is_string($var->name)) {
                $this->defined[] = '$' . $var->name;
            }

            // Также проверяем правую часть на использование переменных
            $this->collectUsedVariables($node->expr);
        }

        // Использование переменной
        // Не добавляем $this, суперглобалы и специальные переменные
        if ($node instanceof Node\Expr\Variable && is_string($node->name) && !$this->isSpecialVariable($node->name)) {
            $varName = '$' . $node->name;
            // Добавляем в used, если не в левой части присваивания
            if (!$this->isLeftSideOfAssignment()) {
                $this->used[] = $varName;
            }
        }

        return null;
    }

    public function leaveNode(Node $node): int|Node|array|null
    {
        // Выходим из вложенной функции
        if ($node instanceof Node\Stmt\Function_
            || $node instanceof Node\Stmt\ClassMethod
            || $node instanceof Node\Expr\Closure
            || $node instanceof Node\Expr\ArrowFunction
        ) {
            $this->depth--;
        }

        return null;
    }

    /**
     * @return array<mixed>
     */
    public function getUsedVariables(): array
    {
        return array_unique($this->used);
    }

    /**
     * @return array<mixed>
     */
    public function getDefinedVariables(): array
    {
        return array_unique($this->defined);
    }

    /**
     * Проверяет, является ли переменная специальной (не параметр)
     */
    private function isSpecialVariable(string $name): bool
    {
        return in_array(
            $name, [
            'this',
            'GLOBALS',
            '_SERVER',
            '_GET',
            '_POST',
            '_FILES',
            '_COOKIE',
            '_SESSION',
            '_REQUEST',
            '_ENV',
            ], true
        );
    }

    /**
     * Проверяет, находится ли переменная в левой части присваивания
     */
    private function isLeftSideOfAssignment(): bool
    {
        // Это упрощенная проверка
        // В реальности нужно проверять родительский узел
        return false;
    }

    /**
     * Рекурсивно собирает используемые переменные из выражения
     */
    private function collectUsedVariables(Node $node): void
    {
        if ($node instanceof Node\Expr\Variable && is_string($node->name)) {
            if (!$this->isSpecialVariable($node->name)) {
                $this->used[] = '$' . $node->name;
            }

            return;
        }

        // Рекурсивно обходим дочерние узлы
        foreach ($node->getSubNodeNames() as $name) {
            $subNode = $node->$name;
            if ($subNode instanceof Node) {
                $this->collectUsedVariables($subNode);
            } elseif (is_array($subNode)) {
                foreach ($subNode as $item) {
                    if ($item instanceof Node) {
                        $this->collectUsedVariables($item);
                    }
                }
            }
        }
    }
}
