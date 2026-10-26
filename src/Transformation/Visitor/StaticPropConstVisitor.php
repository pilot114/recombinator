<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;
use Recombinator\Transformation\BodyInliner;

/**
 * Заменяет чтение статического свойства его значением по умолчанию, если в
 * свойство никто не пишет:
 *
 *   class Provider {
 *       protected static array $factories = ['A', 'B'];
 *       public static function getFactories(): array { return static::$factories; }
 *       public static function setFactories(array $f): void { static::$factories = $f; }
 *   }
 *   →  public static function getFactories(): array { return ['A', 'B']; }
 *
 * Запись не считается, если она в методе, который никто не вызывает: имя метода
 * не встречается ни в одном вызове, ни строкой (`[$cls, 'setFactories']`).
 * Динамический вызов экземпляра (`$obj->{$m}()`) может попасть в любой нестатический
 * метод, динамический статический (`X::$m()`) — в любой метод вообще.
 *
 * `static::$p` сворачивается, только если у класса нет наследников: иначе `static`
 * может означать подкласс со своим значением.
 */
#[VisitorMeta('Неизменяемое статическое свойство → его значение по умолчанию')]
class StaticPropConstVisitor extends BaseVisitor
{
    /** @var array<string, array<string, Node\Expr>> lowercase класс → свойство → значение */
    private array $values = [];

    /** @var array<string, true> классы, у которых есть наследники */
    private array $extended = [];

    private ?string $currentClass = null;

    /**
     * Позиции параметров по ссылке: lowercase имя функции или метода → индексы
     * ('*' — есть вариативный по ссылке). Имя без записи — по ссылке ничего не берёт.
     *
     * @var array<string, array<int|string, true>>
     */
    private array $byRef = [];

    #[\Override]
    public function beforeTraverse(array $nodes): ?array
    {
        parent::beforeTraverse($nodes);
        $this->values       = [];
        $this->extended     = [];
        $this->currentClass = null;
        $this->byRef        = [];
        foreach ($this->findNode(Node\FunctionLike::class) as $fn) {
            $name = match (true) {
                $fn instanceof Node\Stmt\ClassMethod, $fn instanceof Node\Stmt\Function_ => strtolower($fn->name->toString()),
                default => null,
            };
            foreach ($fn->getParams() as $i => $param) {
                if ($name !== null && $param->byRef) {
                    $this->byRef[$name][$param->variadic ? '*' : $i] = true;
                }
            }
        }

        /** @var list<Node\Stmt\Class_> $classes */
        $classes = [];
        foreach ($nodes as $node) {
            foreach ($node instanceof Node\Stmt\Namespace_ ? $node->stmts : [$node] as $stmt) {
                if ($stmt instanceof Node\Stmt\Class_ && $stmt->name instanceof Node\Identifier) {
                    $classes[] = $stmt;
                }
            }
        }

        foreach ($classes as $class) {
            if ($class->extends instanceof Node\Name) {
                $this->extended[strtolower($class->extends->toString())] = true;
            }

            foreach ($class->getProperties() as $property) {
                if (!$property->isStatic()) {
                    continue;
                }

                foreach ($property->props as $prop) {
                    if ($prop->default instanceof Node\Expr && $this->isLiteral($prop->default)) {
                        $this->values[strtolower((string) $class->name)][$prop->name->toString()] = $prop->default;
                    }
                }
            }
        }

        // Встраиваемый позже файл может писать в свойство
        if ($this->values !== [] && $this->hasPendingIncludes()) {
            $this->values = [];
        }

        if ($this->values !== []) {
            foreach (array_keys($this->writtenProps($nodes)) as $name) {
                foreach (array_keys($this->values) as $class) {
                    unset($this->values[$class][$name]);
                }
            }
        }

        return null;
    }

    public function enterNode(Node $node): ?Node
    {
        if ($node instanceof Node\Stmt\Class_ && $node->name instanceof Node\Identifier) {
            $this->currentClass = strtolower($node->name->toString());
        }

        if (
            !$node instanceof Node\Expr\StaticPropertyFetch
            || !$node->class instanceof Node\Name
            || !$node->name instanceof Node\VarLikeIdentifier
            || $this->isWriteTarget($node)
        ) {
            return null;
        }

        $class = strtolower($node->class->toString());
        if ($class === 'self' || $class === 'static') {
            if ($this->currentClass === null || $class === 'static' && isset($this->extended[$this->currentClass])) {
                return null;
            }

            $class = $this->currentClass;
        }

        $value = $this->values[$class][$node->name->toString()] ?? null;
        if (!$value instanceof Node\Expr) {
            return null;
        }

        // new static::$cls() / static::$cls::m(): на месте класса нужно имя, а не строка
        $parent = $node->getAttribute('parent');
        if (
            ($parent instanceof Node\Expr\New_ || $parent instanceof Node\Expr\StaticCall || $parent instanceof Node\Expr\ClassConstFetch)
            && $parent->class === $node
        ) {
            return $value instanceof Node\Scalar\String_ && preg_match('/^\\\\?[A-Za-z_][\w\\\\]*$/', $value->value) === 1
                ? new Node\Name\FullyQualified(ltrim($value->value, '\\'))
                : null;
        }

        return $this->deepClone($value);
    }

    private function argMayBeByRef(Node\Arg $arg): bool
    {
        $call = $arg->getAttribute('parent');
        if (!$call instanceof Node\Expr\CallLike || $call->isFirstClassCallable()) {
            return true;
        }

        $index = array_search($arg, $call->getRawArgs(), true);
        if (!is_int($index)) {
            return true;
        }

        if ($call instanceof Node\Expr\FuncCall && $call->name instanceof Node\Name && function_exists($call->name->getLast())) {
            return BodyInliner::mayPassByRef($call, $index);
        }

        $name = match (true) {
            $call instanceof Node\Expr\FuncCall => $call->name instanceof Node\Name ? strtolower($call->name->getLast()) : null,
            $call instanceof Node\Expr\New_ => '__construct',
            default => $call->name instanceof Node\Identifier ? strtolower($call->name->toString()) : null,
        };
        if ($name === null) {
            return true;
        }

        $refs = $this->byRef[$name] ?? [];

        return $refs !== [] && ($arg->name instanceof Node\Identifier || isset($refs[$index]) || isset($refs['*']));
    }

    #[\Override]
    public function leaveNode(Node $node): int|Node|array|null
    {
        if ($node instanceof Node\Stmt\Class_) {
            $this->currentClass = null;
        }

        return parent::leaveNode($node);
    }

    private function isLiteral(Node\Expr $expr): bool
    {
        if ($expr instanceof Node\Scalar\String_ || $expr instanceof Node\Scalar\Int_ || $expr instanceof Node\Scalar\Float_) {
            return true;
        }

        if ($expr instanceof Node\Expr\ConstFetch) {
            return in_array(strtolower($expr->name->toString()), ['true', 'false', 'null'], true);
        }

        if ($expr instanceof Node\Expr\UnaryMinus) {
            return $this->isLiteral($expr->expr);
        }

        return $expr instanceof Node\Expr\Array_ && array_all(
            $expr->items,
            fn(Node\ArrayItem $item): bool => !$item->byRef
                && !$item->unpack
                && (!$item->key instanceof Node\Expr || $this->isLiteral($item->key))
                && $this->isLiteral($item->value),
        );
    }

    /**
     * Имена статических свойств, в которые пишет код, способный выполниться.
     *
     * @param  array<Node> $nodes
     * @return array<string, true>
     */
    private function writtenProps(array $nodes): array
    {
        $invoked        = [];
        $anyInstance    = false;
        $anyStatic      = false;
        /** @var list<array{string, ?Node\Stmt\ClassMethod}> $writes */
        $writes         = [];
        $methodStack    = [];

        $walk = function (array $nodes) use (&$walk, &$invoked, &$anyInstance, &$anyStatic, &$writes, &$methodStack): void {
            foreach ($nodes as $node) {
                if (!$node instanceof Node) {
                    continue;
                }

                if (
                    $node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\NullsafeMethodCall
                    || $node instanceof Node\Expr\StaticCall
                ) {
                    if ($node->name instanceof Node\Identifier) {
                        $invoked[strtolower($node->name->toString())] = true;
                    } elseif ($node instanceof Node\Expr\StaticCall) {
                        $anyStatic = true;
                    } else {
                        $anyInstance = true;
                    }
                }

                if ($node instanceof Node\Scalar\String_) {
                    $parts = explode('::', $node->value);
                    $invoked[strtolower(end($parts))] = true;
                }

                $target = match (true) {
                    $node instanceof Node\Expr\Assign, $node instanceof Node\Expr\AssignOp, $node instanceof Node\Expr\AssignRef,
                    $node instanceof Node\Expr\PreInc, $node instanceof Node\Expr\PreDec,
                    $node instanceof Node\Expr\PostInc, $node instanceof Node\Expr\PostDec => $node->var,
                    // аргумент может передаваться по ссылке: array_unshift(static::$items, ...)
                    $node instanceof Node\Arg => $this->argMayBeByRef($node) ? $node->value : null,
                    default => null,
                };
                $targets = $node instanceof Node\Stmt\Unset_ ? $node->vars : [$target];
                foreach ($targets as $t) {
                    while ($t instanceof Node\Expr\ArrayDimFetch || $t instanceof Node\Expr\PropertyFetch) {
                        $t = $t->var;
                    }

                    if ($t instanceof Node\Expr\StaticPropertyFetch) {
                        $writes[] = [$t->name instanceof Node\VarLikeIdentifier ? $t->name->toString() : '*', end($methodStack) ?: null];
                    }
                }

                if ($node instanceof Node\Stmt\ClassMethod) {
                    $methodStack[] = $node;
                }

                foreach ($node->getSubNodeNames() as $sub) {
                    $value = $node->$sub;
                    $walk(is_array($value) ? $value : [$value]);
                }

                if ($node instanceof Node\Stmt\ClassMethod) {
                    array_pop($methodStack);
                }
            }
        };
        $walk($nodes);

        $written = [];
        foreach ($writes as [$name, $method]) {
            $reachable = !$method instanceof Node\Stmt\ClassMethod
                || $anyStatic
                || $anyInstance && !$method->isStatic()
                || isset($invoked[strtolower($method->name->toString())])
                || in_array(strtolower($method->name->toString()), ['__construct', '__destruct', '__clone', '__wakeup', '__unserialize'], true)
                || str_starts_with($method->name->toString(), '__');
            if ($reachable) {
                if ($name === '*') {
                    foreach ($this->values as $props) {
                        foreach (array_keys($props) as $prop) {
                            $written[$prop] = true;
                        }
                    }
                }

                $written[$name] = true;
            }
        }

        return $written;
    }

    private function isWriteTarget(Node\Expr\StaticPropertyFetch $node): bool
    {
        $child  = $node;
        $parent = $node->getAttribute('parent');
        while ($parent instanceof Node\Expr\ArrayDimFetch || $parent instanceof Node\Expr\PropertyFetch) {
            $child  = $parent;
            $parent = $parent->getAttribute('parent');
        }

        return ($parent instanceof Node\Expr\Assign || $parent instanceof Node\Expr\AssignOp || $parent instanceof Node\Expr\AssignRef
                || $parent instanceof Node\Expr\PreInc || $parent instanceof Node\Expr\PreDec
                || $parent instanceof Node\Expr\PostInc || $parent instanceof Node\Expr\PostDec)
                && $parent->var === $child
            || $parent instanceof Node\Arg && $this->argMayBeByRef($parent)
            || $parent instanceof Node\Stmt\Unset_
            // isset() принимает только переменные
            || $parent instanceof Node\Expr\Isset_;
    }
}
