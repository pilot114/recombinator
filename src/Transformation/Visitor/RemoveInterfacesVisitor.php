<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;
use Recombinator\Support\TypeInference;

/**
 * Удаляет объявления интерфейсов и их упоминания в implements-списке классов.
 *
 * Что убирается:
 *   - interface Foo { ... }
 *   - class Bar implements Foo, Baz  →  class Bar implements Baz
 *
 * Удаляется только интерфейс, по которому код не проверяет тип: на него нет
 * ссылок в типах параметров/свойств/возврата, instanceof, catch, `Foo::class`,
 * строках. Иначе `f(ContainerInterface $c)` перестал бы принимать контейнер,
 * который больше не implements ContainerInterface. Тип-хинты снимает
 * StripLocalClassTypeHintsVisitor — после этого интерфейс удалится следующим проходом.
 * Предки оставленного интерфейса тоже остаются.
 *
 * Пока в коде есть невстроенный include, ничего не удаляется: подключаемый файл может
 * проверять тип по интерфейсу.
 *
 * Встроенные интерфейсы (Countable, IteratorAggregate, ArrayAccess, …) не трогаются:
 * от них зависит поведение count(), foreach, `$obj[...]`.
 */
#[VisitorMeta('Удаление интерфейсов и implements')]
class RemoveInterfacesVisitor extends BaseVisitor
{
    /** @var array<string, true> lowercase имена удаляемых интерфейсов */
    private array $removable = [];

    /** @var array<string, Node\Stmt\Interface_> lowercase имя → объявление */
    private array $interfaces = [];

    #[\Override]
    public function beforeTraverse(array $nodes): ?array
    {
        parent::beforeTraverse($nodes);
        $this->removable = [];

        /** @var array<string, Node\Stmt\Interface_> $interfaces */
        $interfaces = [];
        foreach ($this->findNode(Node\Stmt\Interface_::class) as $interface) {
            if ($interface->name instanceof Node\Identifier) {
                $interfaces[strtolower($interface->name->toString())] = $interface;
            }
        }

        // Невстроенный include ещё может принести код, проверяющий интерфейс, а снятый
        // implements уже не вернуть (класс встроен раньше)
        if ($interfaces === [] || $this->hasPendingIncludes()) {
            return null;
        }

        $queue = array_keys(array_intersect_key(self::typeReferences($nodes), $interfaces));
        $kept  = [];
        while ($queue !== []) {
            $name = array_pop($queue);
            if (isset($kept[$name]) || !isset($interfaces[$name])) {
                continue;
            }

            $kept[$name] = true;
            foreach ($interfaces[$name]->extends as $parent) {
                $queue[] = strtolower($parent->toString());
            }
        }

        $this->removable  = array_fill_keys(array_keys(array_diff_key($interfaces, $kept)), true);
        $this->interfaces = $interfaces;

        return null;
    }

    public function enterNode(Node $node): ?Node
    {
        if ($this->removable === []) {
            return null;
        }

        if ($node instanceof Node\Stmt\Interface_) {
            if ($node->name instanceof Node\Identifier && isset($this->removable[strtolower($node->name->toString())])) {
                $node->setAttribute('remove', true);
            } else {
                $node->extends = $this->withoutRemovable($node->extends, $node);
            }

            return null;
        }

        if ($node instanceof Node\Stmt\Class_ || $node instanceof Node\Stmt\Enum_) {
            $node->implements = $this->withoutRemovable($node->implements, $node);
        }

        return null;
    }

    /**
     * @param  array<Node\Name> $names
     * @return list<Node\Name>
     */
    private function withoutRemovable(array $names, Node\Stmt\ClassLike $owner): array
    {
        $removed = [];
        // Удаляемый интерфейс заменяется его оставшимися предками: класс, реализовавший
        // EventManagerInterface extends EventDispatcher, должен остаться EventDispatcher
        $result = [];
        $seen   = [];
        $queue  = array_values($names);
        while ($queue !== []) {
            $name  = array_shift($queue);
            $lower = strtolower(ltrim($name->toString(), '\\'));
            if (isset($seen[$lower])) {
                continue;
            }

            $seen[$lower] = true;
            if (!isset($this->removable[$lower])) {
                $result[] = $name;
                continue;
            }

            $removed[] = $lower;
            foreach ($this->interfaces[$lower]->extends as $parent) {
                $queue[] = $parent;
            }
        }

        // Объявленные типы (originalType) ещё называют удалённый интерфейс — его объекты теперь этот класс
        if ($removed !== []) {
            TypeInference::addFormerTypes($owner, $removed);
        }

        return $result;
    }

    /**
     * Имена (lowercase), по которым код проверяет тип или которые называет строкой.
     *
     * @param  array<Node> $nodes
     * @return array<string, true>
     */
    public static function typeReferences(array $nodes, bool $withStaticAccess = true): array
    {
        $names = [];
        $add   = static function (?Node $node) use (&$names): void {
            if ($node instanceof Node\Name) {
                $names[strtolower(ltrim($node->toString(), '\\'))] = true;
            } elseif ($node instanceof Node\NullableType) {
                $names[strtolower(ltrim($node->type->toString(), '\\'))] = true;
            } elseif ($node instanceof Node\UnionType || $node instanceof Node\IntersectionType) {
                foreach (new \PhpParser\NodeFinder()->findInstanceOf($node, Node\Name::class) as $name) {
                    $names[strtolower(ltrim($name->toString(), '\\'))] = true;
                }
            }
        };

        foreach (new \PhpParser\NodeFinder()->findInstanceOf($nodes, Node::class) as $node) {
            match (true) {
                $node instanceof Node\Param, $node instanceof Node\Stmt\Property, $node instanceof Node\Stmt\ClassConst => $add($node->type),
                $node instanceof Node\FunctionLike => $add($node->getReturnType()),
                $node instanceof Node\Expr\Instanceof_ => $add($node->class),
                $node instanceof Node\Stmt\Catch_ => array_map($add, $node->types),
                ($node instanceof Node\Expr\ClassConstFetch || $node instanceof Node\Expr\StaticCall) && $withStaticAccess => $add($node->class),
                $node instanceof Node\Scalar\String_ => $names[strtolower(ltrim($node->value, '\\'))] = true,
                default => null,
            };
        }

        return $names;
    }
}
