<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Modifiers;
use PhpParser\Node;

/**
 * Убирает type-hints, ссылающиеся на классы, объявленные в этом же файле.
 * Это позволяет RemoveUnusedClassVisitor удалить абстрактные классы, а
 * RemoveInterfacesVisitor — интерфейсы, на которые ссылаются только через
 * type-hints, а не через new/instanceof/catch.
 *
 * Снятие типа — расширение: корректная программа ведёт себя так же.
 * - Тип с локальным классом снимается целиком (`Foo|null`, `?Foo`).
 * - У свойств и promoted-параметров тип становится `mixed`: readonly обязано иметь
 *   тип, а типизированное свойство без значения остаётся неинициализированным.
 * - Тип возврата снимается, только если ни один предок не объявляет этот метод с
 *   другим типом (внешний предок проверяется через рефлексию).
 * - Снятый тип параметра снимается и у переопределений: потомок не может быть уже.
 *
 * Исходный тип сохраняется в атрибутах originalType / originalReturnType: по нему
 * другие правила доказывают, что значение не может быть объектом данного класса.
 */
#[VisitorMeta('Удаление type-hints локальных классов')]
class StripLocalClassTypeHintsVisitor extends BaseVisitor
{
    /** @var array<string, true> lowercase class names defined in this AST */
    private array $localClasses = [];

    /** @var array<string, Node\Stmt\ClassLike> lowercase имя → объявление */
    private array $declarations = [];

    #[\Override]
    public function beforeTraverse(array $nodes): ?array
    {
        parent::beforeTraverse($nodes);
        $this->localClasses = [];
        $this->declarations = [];
        foreach ($this->findNode(Node\Stmt\ClassLike::class) as $declaration) {
            if ($declaration->name instanceof Node\Identifier) {
                $this->declarations[strtolower($declaration->name->toString())] = $declaration;
            }
        }

        foreach ($this->findNode(Node\Stmt\Class_::class) as $class) {
            if ($class->name instanceof Node\Identifier) {
                $this->localClasses[strtolower($class->name->toString())] = true;
            }
        }

        foreach ($this->findNode(Node\Stmt\Interface_::class) as $iface) {
            if ($iface->name instanceof Node\Identifier) {
                $this->localClasses[strtolower($iface->name->toString())] = true;
            }
        }

        foreach ($this->findNode(Node\Stmt\Trait_::class) as $trait) {
            if ($trait->name instanceof Node\Identifier) {
                $this->localClasses[strtolower($trait->name->toString())] = true;
            }
        }

        return null;
    }

    #[\Override]
    public function leaveNode(Node $node): int|Node|array|null
    {
        // Strip return types on class methods and functions
        if (
            ($node instanceof Node\Stmt\ClassMethod || $node instanceof Node\Stmt\Function_)
            && $node->returnType instanceof \PhpParser\Node
            && $this->isLocalType($node->returnType)
            && !$this->hasExternalAncestor($node)
            && $this->ancestorsAllowStripping($node)
        ) {
            $node->setAttribute('originalReturnType', $node->getAttribute('originalReturnType') ?? $node->returnType);
            $node->returnType = null;
            return $node;
        }

        // Параметр: promoted — это свойство, тип остаётся (mixed)
        if ($node instanceof Node\Param && $node->type instanceof Node && $this->isLocalType($node->type)) {
            $node->setAttribute('originalType', $node->getAttribute('originalType') ?? $node->type);
            $node->type = ($node->flags & Modifiers::VISIBILITY_MASK) !== 0 || ($node->flags & Modifiers::READONLY) !== 0
                ? new Node\Identifier('mixed')
                : null;
            return $node;
        }

        // Свойство: mixed сохраняет «неинициализированное» состояние и тип у readonly
        if ($node instanceof Node\Stmt\Property && $node->type instanceof Node && $this->isLocalType($node->type)) {
            $node->setAttribute('originalType', $node->getAttribute('originalType') ?? $node->type);
            $node->type = new Node\Identifier('mixed');
            return $node;
        }

        return parent::leaveNode($node);
    }

    /**
     * Метод класса с внешним родителем или интерфейсом (Countable, \\Relay\\Relay): тип
     * возврата там обязан быть совместим с объявлением предка, снимать его нельзя.
     */
    private function hasExternalAncestor(Node $method): bool
    {
        $class = $method->getAttribute('parent');
        if (!$class instanceof Node\Stmt\ClassLike) {
            return false;
        }

        $name = $method instanceof Node\Stmt\ClassMethod ? $method->name->toString() : null;

        return $this->inheritsExternal($class, 0, $name);
    }

    /**
     * Тип возврата снимается только вместе с типом того же метода у всех предков:
     * реализация `get(): LocalProperty` при интерфейсе `get(): ?\\ReflectionProperty`
     * без типа стала бы несовместимой.
     */
    private function ancestorsAllowStripping(Node $method): bool
    {
        $class = $method->getAttribute('parent');
        if (!$method instanceof Node\Stmt\ClassMethod || !$class instanceof Node\Stmt\ClassLike) {
            return true;
        }

        $name    = $method->name->toString();
        $queue   = $this->parentDeclarations($class);
        $visited = [];
        while ($queue !== []) {
            $ancestor = array_pop($queue);
            if (isset($visited[spl_object_id($ancestor)])) {
                continue;
            }

            $visited[spl_object_id($ancestor)] = true;
            $declared = $ancestor->getMethod($name);
            if ($declared instanceof Node\Stmt\ClassMethod && $declared->returnType instanceof Node && !$this->isLocalType($declared->returnType)) {
                return false;
            }

            array_push($queue, ...$this->parentDeclarations($ancestor));
        }

        return true;
    }

    /**
     * Снятый у предка тип параметра снимается и у переопределений: `f($x)` в
     * интерфейсе и `f(object $x)` в классе — потомок уже предка, ошибка компиляции.
     */
    #[\Override]
    public function afterTraverse(array $nodes): ?array
    {
        do {
            $changed = false;
            foreach ($this->declarations as $class) {
                foreach ($class->getMethods() as $method) {
                    if ($method->name->toLowerString() === '__construct') {
                        continue;
                    }

                    foreach (array_values($method->params) as $i => $param) {
                        if ($param->type instanceof Node && $this->ancestorParamUntyped($class, $method->name->toString(), $i)) {
                            $param->setAttribute('originalType', $param->getAttribute('originalType') ?? $param->type);
                            $param->type = ($param->flags & Modifiers::VISIBILITY_MASK) !== 0 ? new Node\Identifier('mixed') : null;
                            $changed     = true;
                        }
                    }
                }
            }
        } while ($changed);

        return null;
    }

    private function ancestorParamUntyped(Node\Stmt\ClassLike $class, string $method, int $index): bool
    {
        $queue   = $this->parentDeclarations($class);
        $visited = [];
        while ($queue !== []) {
            $ancestor = array_pop($queue);
            if (isset($visited[spl_object_id($ancestor)])) {
                continue;
            }

            $visited[spl_object_id($ancestor)] = true;
            $declared = $ancestor->getMethod($method);
            if ($declared instanceof Node\Stmt\ClassMethod && isset($declared->params[$index]) && $declared->params[$index]->type === null) {
                return true;
            }

            array_push($queue, ...$this->parentDeclarations($ancestor));
        }

        return false;
    }

    /** @return list<Node\Stmt\ClassLike> локальные родители и интерфейсы */
    private function parentDeclarations(Node\Stmt\ClassLike $class): array
    {
        $parents = match (true) {
            $class instanceof Node\Stmt\Class_ => [$class->extends, ...$class->implements],
            $class instanceof Node\Stmt\Interface_ => $class->extends,
            $class instanceof Node\Stmt\Enum_ => $class->implements,
            default => [],
        };

        $result = [];
        foreach ($parents as $name) {
            $declaration = $name instanceof Node\Name ? $this->declarations[strtolower(ltrim($name->toString(), '\\'))] ?? null : null;
            if ($declaration instanceof Node\Stmt\ClassLike) {
                $result[] = $declaration;
            }
        }

        return $result;
    }

    /**
     * Есть ли во всей цепочке предков (через локальные классы) внешний класс или
     * интерфейс — объявляющий метод $method, если он задан (известный PHP класс
     * проверяется рефлексией, неизвестный — считается объявляющим).
     */
    private function inheritsExternal(Node\Stmt\ClassLike $class, int $depth, ?string $method = null): bool
    {
        $parents = match (true) {
            $class instanceof Node\Stmt\Class_ => [$class->extends, ...$class->implements],
            $class instanceof Node\Stmt\Interface_ => $class->extends,
            $class instanceof Node\Stmt\Enum_ => $class->implements,
            default => [],
        };

        foreach ($parents as $name) {
            if (!$name instanceof Node\Name) {
                continue;
            }

            $parent = $this->declarations[strtolower(ltrim($name->toString(), '\\'))] ?? null;
            if ($depth > 16) {
                return true;
            }

            if ($parent instanceof Node\Stmt\ClassLike) {
                if ($this->inheritsExternal($parent, $depth + 1, $method)) {
                    return true;
                }

                continue;
            }

            $external = ltrim($name->toString(), '\\');
            $known    = class_exists($external, false) || interface_exists($external, false);
            if ($method === null || !$known || new \ReflectionClass($external)->hasMethod($method)) {
                return true;
            }
        }

        return false;
    }

    private function isLocalType(mixed $type): bool
    {
        // self/parent/static — тоже локальный класс: снимать надо одинаково у реализации и у
        // интерфейса, иначе `add(): self` в интерфейсе несовместим с `add()` в классе
        if ($type instanceof Node\Name || $type instanceof Node\Identifier && strtolower($type->toString()) === 'static') {
            $name = strtolower($type->toString());

            return isset($this->localClasses[$name]) || in_array($name, ['self', 'parent', 'static'], true);
        }

        if ($type instanceof Node\NullableType) {
            return $this->isLocalType($type->type);
        }

        // Объединение снимается целиком, если в нём есть локальный класс
        if ($type instanceof Node\UnionType) {
            return array_any($type->types, fn(Node\Identifier|Node\Name|Node\IntersectionType $t): bool => $this->isLocalType($t));
        }

        return false;
    }
}
