<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Modifiers;
use PhpParser\Node;

/**
 * Разворачивает трейты: копирует методы, свойства и константы трейта в классы,
 * использующие его (`use TraitName;`), и удаляет определение трейта.
 *
 * Трейты, которые используют другие трейты, раскрываются рекурсивно. Правила
 * PHP соблюдаются: член класса перекрывает член трейта, член трейта — член
 * вложенного трейта; `A::m insteadof B` исключает m из B; `m as private alias`
 * добавляет копию под новым именем, `m as protected` меняет видимость.
 *
 * Класс, который использует хотя бы один неизвестный (внешний) трейт, не
 * трогается, и используемые им трейты остаются.
 */
#[VisitorMeta('Разворачивание трейтов: use Trait → методы/свойства в тело класса')]
class TraitInlinerVisitor extends BaseVisitor
{
    /** @var array<string, Node\Stmt\Trait_> lowercase имя → трейт */
    private array $traits = [];

    /** @var array<string, true> трейты, которые нельзя удалять: их использует необработанный класс */
    private array $keep = [];

    #[\Override]
    public function beforeTraverse(array $nodes): ?array
    {
        parent::beforeTraverse($nodes);
        $this->traits = [];
        $this->keep   = [];
        foreach ($nodes as $node) {
            foreach ($node instanceof Node\Stmt\Namespace_ ? $node->stmts : [$node] as $stmt) {
                if ($stmt instanceof Node\Stmt\Trait_ && $stmt->name instanceof Node\Identifier) {
                    $this->traits[strtolower($stmt->name->toString())] = $stmt;
                }
            }
        }

        if ($this->traits === []) {
            return null;
        }

        // Трейт, которым пользуется класс с внешним трейтом или не встроенный в AST трейт, оставляем
        foreach ($this->findNode(Node\Stmt\ClassLike::class) as $class) {
            if (!$class instanceof Node\Stmt\Trait_ && !$this->expandable($class, [])) {
                foreach ($this->usedTraitNames($class) as $name) {
                    $this->markKeep($name, []);
                }
            }
        }

        return null;
    }

    public function enterNode(Node $node): ?Node
    {
        if (!$node instanceof Node\Stmt\Class_ && !$node instanceof Node\Stmt\Enum_ || $this->usedTraitNames($node) === []) {
            return null;
        }

        if (!$this->expandable($node, [])) {
            return null;
        }

        $own = [];
        foreach ($node->stmts as $stmt) {
            if (!$stmt instanceof Node\Stmt\TraitUse) {
                $own[] = $stmt;
            }
        }

        $node->stmts = $this->merge($this->traitMembers($node, []), $own);

        return null;
    }

    #[\Override]
    public function leaveNode(Node $node): int|Node|array|null
    {
        // Только трейты верхнего уровня: условно объявленные (`if (...) { trait T {} }`) не раскрываются
        if (
            $node instanceof Node\Stmt\Trait_
            && $node->name instanceof Node\Identifier
            && ($this->traits[strtolower($node->name->toString())] ?? null) === $node
            && !isset($this->keep[strtolower($node->name->toString())])
        ) {
            $node->setAttribute('remove', true);
        }

        return parent::leaveNode($node);
    }

    /**
     * Члены всех трейтов, которые использует $class, с учётом insteadof/as.
     *
     * @param  array<string, true> $stack
     * @return list<Node\Stmt>
     */
    private function traitMembers(Node\Stmt\ClassLike $class, array $stack): array
    {
        $members = [];
        foreach ($class->getTraitUses() as $use) {
            $excluded = [];
            $aliases  = [];
            foreach ($use->adaptations as $adaptation) {
                if ($adaptation instanceof Node\Stmt\TraitUseAdaptation\Precedence) {
                    foreach ($adaptation->insteadof as $loser) {
                        $excluded[strtolower($loser->toString())][strtolower($adaptation->method->toString())] = true;
                    }
                } elseif ($adaptation instanceof Node\Stmt\TraitUseAdaptation\Alias) {
                    $aliases[] = $adaptation;
                }
            }

            foreach ($use->traits as $traitName) {
                $lower = strtolower($traitName->toString());
                $trait = $this->traits[$lower];
                $own   = array_values(array_filter($trait->stmts, static fn(Node\Stmt $s): bool => !$s instanceof Node\Stmt\TraitUse));
                $body  = $this->merge($this->traitMembers($trait, $stack + [$lower => true]), $own);

                foreach ($body as $member) {
                    if (
                        $member instanceof Node\Stmt\ClassMethod
                        && isset($excluded[$lower][strtolower($member->name->toString())])
                    ) {
                        continue;
                    }

                    $members[] = $this->deepClone($member);
                }

                foreach ($aliases as $alias) {
                    if ($alias->trait instanceof Node\Name && strtolower($alias->trait->toString()) !== $lower) {
                        continue;
                    }

                    $method = null;
                    foreach ($body as $member) {
                        if ($member instanceof Node\Stmt\ClassMethod && strcasecmp($member->name->toString(), $alias->method->toString()) === 0) {
                            $method = $member;
                        }
                    }

                    if (!$method instanceof Node\Stmt\ClassMethod) {
                        continue;
                    }

                    $copy = $this->deepClone($method);
                    assert($copy instanceof Node\Stmt\ClassMethod);
                    if ($alias->newModifier !== null) {
                        $copy->flags = $copy->flags & ~Modifiers::VISIBILITY_MASK | $alias->newModifier;
                    }

                    if ($alias->newName instanceof Node\Identifier) {
                        $copy->name = new Node\Identifier($alias->newName->toString());
                        $members[]  = $copy;
                    } else {
                        // `m as protected` — меняется только видимость уже скопированного метода
                        foreach ($members as $member) {
                            if ($member instanceof Node\Stmt\ClassMethod && strcasecmp($member->name->toString(), $copy->name->toString()) === 0) {
                                $member->flags = $copy->flags;
                            }
                        }
                    }
                }
            }
        }

        return $members;
    }

    /**
     * Члены трейтов, перекрытые своими членами: свой метод (кроме абстрактного),
     * своё свойство и константа побеждают.
     *
     * @param  list<Node\Stmt> $inherited
     * @param  list<Node\Stmt> $own
     * @return list<Node\Stmt>
     */
    private function merge(array $inherited, array $own): array
    {
        $methods = [];
        $props   = [];
        $consts  = [];
        foreach ($own as $stmt) {
            if ($stmt instanceof Node\Stmt\ClassMethod && !$stmt->isAbstract()) {
                $methods[strtolower($stmt->name->toString())] = true;
            } elseif ($stmt instanceof Node\Stmt\Property) {
                foreach ($stmt->props as $prop) {
                    $props[$prop->name->toString()] = true;
                }
            } elseif ($stmt instanceof Node\Stmt\ClassConst) {
                foreach ($stmt->consts as $const) {
                    $consts[$const->name->toString()] = true;
                }
            }
        }

        $result = [];
        foreach ($inherited as $stmt) {
            if ($stmt instanceof Node\Stmt\ClassMethod) {
                $name = strtolower($stmt->name->toString());
                // Абстрактный метод трейта не нужен, если реализация уже есть
                if (isset($methods[$name]) || $stmt->isAbstract() && $this->hasMethod($result, $name)) {
                    continue;
                }

                // Реализация заменяет ранее скопированный абстрактный метод другого трейта
                $result = array_values(array_filter(
                    $result,
                    static fn(Node\Stmt $s): bool => !$s instanceof Node\Stmt\ClassMethod || strtolower($s->name->toString()) !== $name,
                ));
            } elseif ($stmt instanceof Node\Stmt\Property) {
                $stmt->props = array_values(array_filter(
                    $stmt->props,
                    static fn(Node\PropertyItem $p): bool => !isset($props[$p->name->toString()]),
                ));
                if ($stmt->props === []) {
                    continue;
                }

                foreach ($stmt->props as $prop) {
                    $props[$prop->name->toString()] = true;
                }
            } elseif ($stmt instanceof Node\Stmt\ClassConst) {
                $stmt->consts = array_values(array_filter($stmt->consts, static fn(Node\Const_ $c): bool => !isset($consts[$c->name->toString()])));
                if ($stmt->consts === []) {
                    continue;
                }

                foreach ($stmt->consts as $const) {
                    $consts[$const->name->toString()] = true;
                }
            }

            $result[] = $stmt;
        }

        // Абстрактные методы класса, реализованные трейтом, не нужны
        $own = array_values(array_filter(
            $own,
            fn(Node\Stmt $s): bool => !$s instanceof Node\Stmt\ClassMethod
                || !$s->isAbstract()
                || !$this->hasMethod($result, strtolower($s->name->toString())),
        ));

        return [...$result, ...$own];
    }

    /** @param list<Node\Stmt> $stmts */
    private function hasMethod(array $stmts, string $name): bool
    {
        return array_any($stmts, static fn(Node\Stmt $s): bool => $s instanceof Node\Stmt\ClassMethod && strtolower($s->name->toString()) === $name);
    }

    /**
     * Все трейты класса (и вложенные) известны и без циклов.
     *
     * @param array<string, true> $stack
     */
    private function expandable(Node\Stmt\ClassLike $class, array $stack): bool
    {
        foreach ($this->usedTraitNames($class) as $name) {
            if (!isset($this->traits[$name]) || isset($stack[$name])) {
                return false;
            }

            if (!$this->expandable($this->traits[$name], $stack + [$name => true])) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, true> $stack */
    private function markKeep(string $name, array $stack): void
    {
        if (isset($this->keep[$name]) || isset($stack[$name]) || !isset($this->traits[$name])) {
            return;
        }

        $this->keep[$name] = true;
        foreach ($this->usedTraitNames($this->traits[$name]) as $inner) {
            $this->markKeep($inner, $stack + [$name => true]);
        }
    }

    /** @return list<string> */
    private function usedTraitNames(Node\Stmt\ClassLike $class): array
    {
        $names = [];
        foreach ($class->getTraitUses() as $use) {
            foreach ($use->traits as $trait) {
                $names[] = strtolower(ltrim($trait->toString(), '\\'));
            }
        }

        return $names;
    }
}
