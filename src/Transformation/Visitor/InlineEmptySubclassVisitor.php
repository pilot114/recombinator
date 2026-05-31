<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;

/**
 * Инлайнинг пустых подклассов: заменяет все внешние ссылки на пустой подкласс
 * (без собственных методов/свойств) на имя родительского класса.
 *
 * После этого RemoveUnusedClassVisitor удалит ставший ненужным класс.
 *
 * Пример:
 *   class FooException extends RuntimeException {}
 *   throw new FooException('msg');   → throw new RuntimeException('msg');
 *   catch (FooException $e)          → catch (RuntimeException $e)
 */
#[VisitorMeta('Замена пустых подклассов на родительский класс')]
class InlineEmptySubclassVisitor extends BaseVisitor
{
    /** @var array<string, string> childName → parentName */
    private array $toReplace = [];

    #[\Override]
    public function beforeTraverse(array $nodes): ?array
    {
        parent::beforeTraverse($nodes);

        $this->toReplace = [];

        foreach ($this->findNode(Node\Stmt\Class_::class) as $class) {
            if (!$class->name instanceof Node\Identifier) {
                continue;
            }

            if (!$class->extends instanceof Node\Name) {
                continue;
            }

            if ($class->stmts !== []) {
                continue;
            }

            $childName  = $class->name->toString();
            $parentName = $class->extends->toString();
            $this->toReplace[$childName] = $parentName;
        }

        return null;
    }

    public function enterNode(Node $node): int|Node|array|null
    {
        if ($this->toReplace === []) {
            return null;
        }

        if (!($node instanceof Node\Name)) {
            return null;
        }

        $name = $node->toString();
        if (!isset($this->toReplace[$name])) {
            return null;
        }

        // Не трогаем имя класса в его собственном объявлении (там Node\Identifier, не Node\Name)
        // и ссылку extends внутри определения самого класса (чтобы не сломать структуру класса)
        $parent = $node->getAttribute('parent');
        if ($parent instanceof Node\Stmt\Class_ && $parent->extends === $node) {
            // Это конструкция `class Child extends Child` — исключение не нужно, но на всякий случай
            return null;
        }

        return new Node\Name($this->toReplace[$name]);
    }
}
