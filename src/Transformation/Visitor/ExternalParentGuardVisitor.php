<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;

/**
 * Объявление класса, чей родитель, интерфейс или трейт не объявлен в коде и не
 * встроен в PHP, оборачивается в проверку:
 *
 *   class RelayProxy extends \Relay\Relay { ... }
 *   →
 *   if (class_exists('Relay\Relay') || interface_exists('Relay\Relay')) {
 *       class RelayProxy extends \Relay\Relay { ... }
 *   }
 *
 * В проекте такой класс подключал автозагрузчик только при обращении к нему, а
 * после встраивания все классы объявляются сразу: отсутствующий родитель (здесь —
 * класс расширения relay) ронял бы весь файл при загрузке. Наследники такого
 * класса оборачиваются той же проверкой.
 */
#[VisitorMeta('Условное объявление классов с внешним родителем', targets: [Node\Stmt\ClassLike::class])]
class ExternalParentGuardVisitor extends BaseVisitor
{
    #[\Override]
    public function beforeTraverse(array $nodes): ?array
    {
        parent::beforeTraverse($nodes);

        /** @var array<string, Node\Stmt\ClassLike> $declared */
        $declared = [];
        foreach ($this->findNode(Node\Stmt\ClassLike::class) as $class) {
            if ($class->name instanceof Node\Identifier) {
                $declared[strtolower($class->name->toString())] = $class;
            }
        }

        // Внешние имена, от которых зависит каждый класс (с учётом предков)
        $missing = [];
        $resolve = function (string $name, array $stack) use (&$resolve, &$missing, $declared): array {
            if (isset($missing[$name]) || isset($stack[$name])) {
                return $missing[$name] ?? [];
            }

            $result = [];
            foreach ($this->parentNames($declared[$name]) as $parent) {
                $lower = strtolower(ltrim($parent, '\\'));
                if (isset($declared[$lower])) {
                    $result += $resolve($lower, $stack + [$name => true]);
                } elseif (!$this->existsInPhp($parent)) {
                    $result[ltrim($parent, '\\')] = true;
                }
            }

            return $missing[$name] = $result;
        };

        $changed = false;
        foreach ($nodes as $i => $node) {
            if (!$node instanceof Node\Stmt\ClassLike || !$node->name instanceof Node\Identifier) {
                continue;
            }

            $external = array_map(strval(...), array_keys($resolve(strtolower($node->name->toString()), [])));
            if ($external === []) {
                continue;
            }

            $nodes[$i] = new Node\Stmt\If_($this->existsCondition($external), ['stmts' => [$node]]);
            $changed   = true;
        }

        return $changed ? $nodes : null;
    }

    /** @return list<string> */
    private function parentNames(Node\Stmt\ClassLike $class): array
    {
        $names = match (true) {
            $class instanceof Node\Stmt\Class_ => [$class->extends, ...$class->implements],
            $class instanceof Node\Stmt\Interface_ => $class->extends,
            $class instanceof Node\Stmt\Enum_ => $class->implements,
            default => [],
        };
        foreach ($class->getTraitUses() as $use) {
            array_push($names, ...$use->traits);
        }

        return array_values(array_map(
            static fn(Node\Name $name): string => $name->toString(),
            array_filter($names, static fn(?Node\Name $name): bool => $name instanceof Node\Name),
        ));
    }

    private function existsInPhp(string $name): bool
    {
        $name = ltrim($name, '\\');
        if (!class_exists($name, false) && !interface_exists($name, false) && !trait_exists($name, false)) {
            return false;
        }

        return new \ReflectionClass($name)->isInternal();
    }

    /** @param list<string> $names */
    private function existsCondition(array $names): Node\Expr
    {
        $condition = null;
        foreach ($names as $name) {
            $check = new Node\Expr\BinaryOp\BooleanOr(
                new Node\Expr\BinaryOp\BooleanOr($this->exists('class_exists', $name), $this->exists('interface_exists', $name)),
                $this->exists('trait_exists', $name),
            );
            $condition = $condition === null ? $check : new Node\Expr\BinaryOp\BooleanAnd($condition, $check);
        }

        return $condition ?? new Node\Expr\ConstFetch(new Node\Name('true'));
    }

    /** Класс расширения может быть на сервере: ClassExistsFoldVisitor не сворачивает эту проверку. */
    private function exists(string $function, string $name): Node\Expr\FuncCall
    {
        return new Node\Expr\FuncCall(new Node\Name($function), [new Node\Arg(new Node\Scalar\String_($name))], ['runtimeCheck' => true]);
    }
}
