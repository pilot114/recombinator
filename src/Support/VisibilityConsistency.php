<?php

declare(strict_types=1);

namespace Recombinator\Support;

use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\NodeFinder;

/**
 * Видимость члена наследника не может быть уже, чем у предка (`Access level to
 * Child::$x must be public (as in class Parent)`). Правила, делающие члены public
 * (StaticMethodExtractVisitor, FlattenInheritanceVisitor), расширяют видимость в
 * классе; здесь то же расширение переносится на одноимённые члены наследников.
 */
final class VisibilityConsistency
{
    /**
     * @param  array<Node> $nodes
     * @return bool изменено ли что-нибудь
     */
    public static function apply(array $nodes): bool
    {
        $classes = [];
        foreach (new NodeFinder()->findInstanceOf($nodes, Node\Stmt\Class_::class) as $class) {
            if ($class->name instanceof Node\Identifier) {
                $classes[$class->name->toLowerString()] = $class;
            }
        }

        $changed = false;
        foreach ($classes as $class) {
            $public = self::publicMembers($class, $classes, 0);
            foreach ($class->stmts as $stmt) {
                if (
                    ($stmt instanceof Node\Stmt\Property || $stmt instanceof Node\Stmt\ClassConst || $stmt instanceof Node\Stmt\ClassMethod)
                    && ($stmt->flags & Modifiers::PUBLIC) === 0 && array_any(self::names($stmt), static fn(string $n): bool => isset($public[$n]))
                ) {
                    $stmt->flags = $stmt->flags & ~(Modifiers::PRIVATE | Modifiers::PROTECTED) | Modifiers::PUBLIC;
                    $changed     = true;
                }
            }
        }

        return $changed;
    }

    /**
     * Публичные члены предков: `$имя`, `const:имя`, `fn:имя`.
     *
     * @param  array<string, Node\Stmt\Class_> $classes
     * @return array<string, true>
     */
    private static function publicMembers(Node\Stmt\Class_ $class, array $classes, int $depth): array
    {
        $parent = $class->extends instanceof Node\Name ? $classes[strtolower(ltrim($class->extends->toString(), '\\'))] ?? null : null;
        if (!$parent instanceof Node\Stmt\Class_ || $depth > 32) {
            return [];
        }

        $result = self::publicMembers($parent, $classes, $depth + 1);
        foreach ($parent->stmts as $stmt) {
            if (
                ($stmt instanceof Node\Stmt\Property || $stmt instanceof Node\Stmt\ClassConst || $stmt instanceof Node\Stmt\ClassMethod)
                && (($stmt->flags & Modifiers::PUBLIC) !== 0 || ($stmt->flags & (Modifiers::PRIVATE | Modifiers::PROTECTED)) === 0)
            ) {
                foreach (self::names($stmt) as $name) {
                    $result[$name] = true;
                }
            }
        }

        return $result;
    }

    /** @return list<string> */
    private static function names(Node\Stmt\Property|Node\Stmt\ClassConst|Node\Stmt\ClassMethod $stmt): array
    {
        return match (true) {
            $stmt instanceof Node\Stmt\Property => array_map(static fn(Node\PropertyItem $p): string => '$' . $p->name->toString(), $stmt->props),
            $stmt instanceof Node\Stmt\ClassConst => array_map(static fn(Node\Const_ $c): string => 'const:' . $c->name->toString(), $stmt->consts),
            default => ['fn:' . $stmt->name->toLowerString()],
        };
    }
}
