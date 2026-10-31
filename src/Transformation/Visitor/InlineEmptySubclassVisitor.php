<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;
use Recombinator\Support\TypeInference;

/**
 * Инлайнинг пустых подклассов: заменяет все внешние ссылки на пустой подкласс
 * (без собственных методов/свойств) на имя родительского класса.
 *
 * После этого RemoveUnusedClassVisitor удалит ставший ненужным класс.
 *
 * Пример:
 *   class EmptyFoo extends Foo {}
 *   new EmptyFoo('x');   → new Foo('x');
 *
 * Подклассы исключений не трогаются: их класс виден в логе и на странице ошибки.
 * Подкласс остаётся, если код отличает его от родителя: `catch (FooException)`,
 * `instanceof FooException`, `FooException::class`, имя строкой. Иначе после замены
 * `catch` перестал бы ловить только свой тип, а ловил бы все исключения родителя
 * (или ничего — если в catch стоял родитель, а бросали подкласс другой ветки).
 * Подкласс с интерфейсами или атрибутами тоже не пустой: он добавляет тип.
 */
#[VisitorMeta('Замена пустых подклассов на родительский класс', targets: [Node\Stmt\Class_::class])]
class InlineEmptySubclassVisitor extends BaseVisitor
{
    /** @var array<string, string> childName → parentName */
    private array $toReplace = [];

    #[\Override]
    public function beforeTraverse(array $nodes): ?array
    {
        parent::beforeTraverse($nodes);

        $this->toReplace = [];
        if ($this->hasPendingIncludes()) {
            return null;
        }

        $distinguished = $this->distinguishedNames();
        $classes       = [];
        foreach ($this->findNode(Node\Stmt\Class_::class) as $class) {
            if ($class->name instanceof Node\Identifier) {
                $classes[strtolower($class->name->toString())] = $class;
            }
        }

        foreach ($classes as $class) {
            if (!$class->name instanceof Node\Identifier) {
                continue;
            }

            if (!$class->extends instanceof Node\Name) {
                continue;
            }

            if ($class->stmts !== [] || $class->implements !== [] || $class->attrGroups !== []) {
                continue;
            }

            if (isset($distinguished[strtolower($class->name->toString())]) || $this->isThrowable($class, $classes)) {
                continue;
            }

            $childName  = $class->name->toString();
            $parentName = $class->extends->toString();
            $this->toReplace[$childName] = $parentName;

            // Объекты пустого подкласса станут объектами родителя
            $parentClass = $classes[strtolower(ltrim($parentName, '\\'))] ?? null;
            if ($parentClass instanceof Node\Stmt\ClassLike) {
                TypeInference::addFormerTypes($parentClass, [strtolower($childName), ...TypeInference::formerTypes($class)]);
            }
        }

        return null;
    }

    /**
     * Класс исключения виден снаружи (страница ошибки, лог печатают get_class),
     * поэтому подклассы Throwable не заменяются родителем.
     *
     * @param array<string, Node\Stmt\Class_> $classes
     */
    private function isThrowable(Node\Stmt\Class_ $class, array $classes): bool
    {
        for ($depth = 0; $class->extends instanceof Node\Name && $depth < 32; $depth++) {
            $parent = ltrim($class->extends->toString(), '\\');
            if (!isset($classes[strtolower($parent)])) {
                return is_a($parent, \Throwable::class, true);
            }

            $class = $classes[strtolower($parent)];
        }

        return false;
    }

    /**
     * Имена классов (lowercase), по которым код различает типы.
     *
     * @return array<string, true>
     */
    private function distinguishedNames(): array
    {
        $names = [];
        $add   = static function (Node $name) use (&$names): void {
            if ($name instanceof Node\Name) {
                $names[strtolower($name->toString())] = true;
            }
        };

        foreach ($this->findNode(Node::class) as $node) {
            match (true) {
                $node instanceof Node\Stmt\Catch_ => array_map($add, $node->types),
                $node instanceof Node\Expr\Instanceof_ => $add($node->class),
                $node instanceof Node\Expr\ClassConstFetch
                    && $node->name instanceof Node\Identifier
                    && strtolower($node->name->toString()) === 'class' => $add($node->class),
                $node instanceof Node\Scalar\String_ => $names[strtolower(ltrim($node->value, '\\'))] = true,
                default => null,
            };
        }

        return $names;
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
