<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;

/**
 * Подставляет значение глобальной константы, объявленной один раз на верхнем уровне:
 *
 *   const APP_ROOT = '/srv/app';
 *   ... 'path' => APP_ROOT . '/var/database.sqlite' ...
 *   →
 *   ... 'path' => '/srv/app' . '/var/database.sqlite' ...
 *
 * Объявление — `const X = литерал;` или `define('X', литерал)` инструкцией верхнего
 * уровня (не в условии и не в функции), и других объявлений у имени нет: значение одно
 * на всё время выполнения. Объявление остаётся (константу может читать код вне
 * программы).
 */
#[VisitorMeta('Подстановка глобальных констант верхнего уровня')]
class GlobalConstantVisitor extends BaseVisitor
{
    /** @var array<string, Node\Expr> имя → значение */
    private array $values = [];

    /** @var array<string, int> имя → номер инструкции верхнего уровня с объявлением */
    private array $position = [];

    /** @var list<Node> */
    private array $topLevel = [];

    #[\Override]
    public function beforeTraverse(array $nodes): ?array
    {
        parent::beforeTraverse($nodes);
        $this->values   = [];
        $this->position = [];
        $this->topLevel = array_values($nodes);

        $counts = [];
        foreach ($this->findNode(Node::class) as $node) {
            // define() считается по самому вызову (инструкция-обёртка дала бы второй раз)
            if ($node instanceof Node\Stmt\Expression) {
                continue;
            }

            foreach ($this->declared($node) as $name => $value) {
                $counts[$name] = ($counts[$name] ?? 0) + 1;
            }
        }

        foreach ($this->topLevel as $index => $stmt) {
            foreach ($this->declared($stmt) as $name => $value) {
                if ($counts[$name] === 1 && $value instanceof Node\Expr && $this->isLiteral($value)) {
                    $this->values[$name]   = $value;
                    $this->position[$name] = $index;
                }
            }
        }

        return null;
    }

    public function enterNode(Node $node): ?Node
    {
        if (!$node instanceof Node\Expr\ConstFetch || $this->values === []) {
            return null;
        }

        $name = ltrim($node->name->toString(), '\\');
        if (!isset($this->values[$name]) || !$this->afterDeclaration($node, $this->position[$name])) {
            return null;
        }

        $value = $this->deepClone($this->values[$name]);
        assert($value instanceof Node\Expr);

        return $value;
    }

    /**
     * Обращение — в инструкции верхнего уровня ниже объявления (в том числе внутри
     * замыканий и функций, объявленных там). Тела функций и классов выше объявления не
     * трогаются: они могут выполниться раньше, чем константа появится.
     */
    private function afterDeclaration(Node $node, int $declaration): bool
    {
        $top = $node;
        for ($parent = $node->getAttribute('parent'); $parent instanceof Node; $parent = $parent->getAttribute('parent')) {
            $top = $parent;
        }

        $index = array_search($top, $this->topLevel, true);

        return is_int($index) && $index > $declaration;
    }

    /** @return array<string, Node\Expr|null> */
    private function declared(Node $node): array
    {
        if ($node instanceof Node\Stmt\Const_) {
            $result = [];
            foreach ($node->consts as $const) {
                $result[$const->name->toString()] = $const->value;
            }

            return $result;
        }

        $call = $node instanceof Node\Stmt\Expression ? $node->expr : $node;
        if (
            $call instanceof Node\Expr\FuncCall && $call->name instanceof Node\Name && $call->name->toLowerString() === 'define'
            && ($call->args[0] ?? null) instanceof Node\Arg && $call->args[0]->value instanceof Node\Scalar\String_
        ) {
            $value = ($call->args[1] ?? null) instanceof Node\Arg ? $call->args[1]->value : null;

            return [ltrim($call->args[0]->value->value, '\\') => $node instanceof Node\Stmt\Expression ? $value : null];
        }

        return [];
    }

    private function isLiteral(Node\Expr $expr): bool
    {
        return match (true) {
            $expr instanceof Node\Scalar\String_, $expr instanceof Node\Scalar\Int_, $expr instanceof Node\Scalar\Float_ => true,
            $expr instanceof Node\Expr\ConstFetch => in_array($expr->name->toLowerString(), ['true', 'false', 'null'], true),
            default => false,
        };
    }
}
