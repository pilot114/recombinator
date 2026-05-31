<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;

/**
 * Разворачивает трейты: копирует методы и свойства трейта в классы,
 * использующие его (`use TraitName;`), и удаляет определение трейта.
 *
 * За один проход обрабатывается один уровень use-trait.
 * Если трейт сам использует другой трейт, следующий проход фиксированной
 * точки доберётся до него.
 *
 * Что копируется: свойства, методы (не переопределённые в классе).
 * `use TraitName;` внутри класса удаляется.
 * Само определение трейта помечается на удаление.
 */
#[VisitorMeta('Разворачивание трейтов: use Trait → методы/свойства в тело класса')]
class TraitInlinerVisitor extends BaseVisitor
{
    /** @var array<string, Node\Stmt\Trait_> */
    private array $traits = [];

    #[\Override]
    public function beforeTraverse(array $nodes): ?array
    {
        parent::beforeTraverse($nodes);
        $this->traits = [];
        $this->collectTraits($nodes);
        return null;
    }

    /** @param array<Node> $nodes */
    private function collectTraits(array $nodes): void
    {
        foreach ($nodes as $node) {
            if ($node instanceof Node\Stmt\Trait_ && $node->name instanceof Node\Identifier) {
                $this->traits[$node->name->name] = $node;
            }
        }
    }

    public function enterNode(Node $node): ?Node
    {
        if (!$node instanceof Node\Stmt\Class_) {
            return null;
        }

        // Находим все TraitUse в классе
        $traitDefs = [];
        foreach ($node->stmts as $stmt) {
            if (!$stmt instanceof Node\Stmt\TraitUse) {
                continue;
            }

            foreach ($stmt->traits as $traitName) {
                $name = $traitName->toString();
                if (isset($this->traits[$name])) {
                    $traitDefs[] = $this->traits[$name];
                }
            }

            $stmt->setAttribute('remove', true);
        }

        if ($traitDefs === []) {
            return null;
        }

        // Собираем имена уже существующих методов/свойств в классе
        $existingMethods = [];
        $existingProps   = [];
        foreach ($node->stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\ClassMethod && $stmt->name instanceof Node\Identifier) {
                $existingMethods[$stmt->name->name] = true;
            }

            if ($stmt instanceof Node\Stmt\Property) {
                foreach ($stmt->props as $prop) {
                    if ($prop instanceof Node\PropertyItem) {
                        $existingProps[$prop->name->name] = true;
                    }
                }
            }
        }

        // Копируем члены трейта в класс (без перекрытых)
        $newStmts = [];
        foreach ($traitDefs as $trait) {
            foreach ($trait->stmts as $traitStmt) {
                if ($traitStmt instanceof Node\Stmt\ClassMethod && $traitStmt->name instanceof Node\Identifier) {
                    $methodName = $traitStmt->name->name;
                    if (!isset($existingMethods[$methodName])) {
                        $newStmts[]                      = $this->deepClone($traitStmt);
                        $existingMethods[$methodName]    = true;
                    }
                } elseif ($traitStmt instanceof Node\Stmt\Property) {
                    $kept = [];
                    foreach ($traitStmt->props as $prop) {
                        if ($prop instanceof Node\PropertyItem && !isset($existingProps[$prop->name->name])) {
                            $kept[]                          = $prop;
                            $existingProps[$prop->name->name] = true;
                        }
                    }

                    if ($kept !== []) {
                        $copy        = $this->deepClone($traitStmt);
                        $copy->props = $kept;
                        $newStmts[]  = $copy;
                    }
                }
            }
        }

        if ($newStmts !== []) {
            $node->stmts = array_merge($newStmts, $node->stmts);
        }

        return null;
    }

    #[\Override]
    public function leaveNode(Node $node): int|Node|array|null
    {
        if ($node instanceof Node\Stmt\Trait_) {
            $node->setAttribute('remove', true);
        }

        return parent::leaveNode($node);
    }
}
