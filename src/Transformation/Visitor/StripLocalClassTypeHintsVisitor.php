<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Modifiers;
use PhpParser\Node;

/**
 * Убирает type-hints, ссылающиеся на классы, объявленные в этом же файле.
 * Это позволяет RemoveUnusedClassVisitor удалить абстрактные классы,
 * на которые ссылаются только через type-hints, а не через new/instanceof.
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
            $node->returnType = null;
            return $node;
        }

        // Strip param types (readonly-свойство без типа — ошибка компиляции, его не трогаем)
        if (
            $node instanceof Node\Param
            && $node->type instanceof \PhpParser\Node
            && $this->isLocalType($node->type)
            && !$this->isReadonly($node)
        ) {
            $node->type = null;
            return $node;
        }

        // Strip property types
        if (
            $node instanceof Node\Stmt\Property
            && $node->type instanceof \PhpParser\Node
            && $this->isLocalType($node->type)
            && !$this->isReadonly($node)
        ) {
            $node->type = null;
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

        return $class instanceof Node\Stmt\ClassLike && $this->inheritsExternal($class, 0);
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

    /** Есть ли во всей цепочке предков (через локальные классы) внешний класс или интерфейс. */
    private function inheritsExternal(Node\Stmt\ClassLike $class, int $depth): bool
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
            if (!$parent instanceof Node\Stmt\ClassLike || $depth > 16 || $this->inheritsExternal($parent, $depth + 1)) {
                return true;
            }
        }

        return false;
    }

    /** Readonly-свойство (в том числе promoted-параметр или член readonly-класса) обязано иметь тип. */
    private function isReadonly(Node\Param|Node\Stmt\Property $node): bool
    {
        $class = $node->getAttribute('parent');
        while ($class instanceof Node && !$class instanceof Node\Stmt\Class_) {
            $class = $class->getAttribute('parent');
        }

        $inReadonlyClass = $class instanceof Node\Stmt\Class_ && $class->isReadonly();
        if ($node instanceof Node\Stmt\Property) {
            return $node->isReadonly() || $inReadonlyClass;
        }

        $promoted = ($node->flags & Modifiers::VISIBILITY_MASK) !== 0;

        return ($node->flags & Modifiers::READONLY) !== 0 || ($promoted && $inReadonlyClass);
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

        if ($type instanceof Node\UnionType) {
            return array_all($type->types, fn(Node\Identifier|Node\Name|Node\IntersectionType $t): bool => $this->isLocalType($t));
        }

        return false;
    }
}
