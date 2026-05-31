<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

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

    #[\Override]
    public function beforeTraverse(array $nodes): ?array
    {
        parent::beforeTraverse($nodes);
        $this->localClasses = [];

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
        ) {
            $node->returnType = null;
            return $node;
        }

        // Strip param types
        if ($node instanceof Node\Param && $node->type instanceof \PhpParser\Node && $this->isLocalType($node->type)) {
            $node->type = null;
            return $node;
        }

        // Strip property types
        if ($node instanceof Node\Stmt\Property && $node->type instanceof \PhpParser\Node && $this->isLocalType($node->type)) {
            $node->type = null;
            return $node;
        }

        return parent::leaveNode($node);
    }

    private function isLocalType(mixed $type): bool
    {
        if ($type instanceof Node\Name) {
            return isset($this->localClasses[strtolower($type->toString())]);
        }

        if ($type instanceof Node\NullableType) {
            return $this->isLocalType($type->type);
        }

        if ($type instanceof Node\UnionType) {
            return array_all($type->types, fn($t): bool => $this->isLocalType($t));
        }

        return false;
    }
}
