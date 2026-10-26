<?php

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;
use Recombinator\Domain\ScopeStore;

/**
 * Убираем константы классов
 * - подменяем использование внутри класса (self::, static::, parent::)
 * - подменяем использование вне класса (ClassName::CONST)
 * - объявление удаляем, когда на константу больше никто не ссылается
 *
 * Константа ищется у своего класса, а если не объявлена там — у родителя и
 * интерфейсов: одноимённые константы разных классов (`ParameterType::STRING`,
 * `TokenType::STRING`) — разные значения. Case enum — не константа и не трогается.
 * `static::X` разрешается, только если у класса нет наследников (иначе static —
 * подкласс со своим X).
 */
#[VisitorMeta('Инлайн констант классов: ClassName::CONST → значение')]
class ConstClassVisitor extends BaseVisitor
{
    protected ScopeStore $scopeStore;

    private const array REFLECTION_CONSTANT_METHODS = ['getconstants', 'getconstant', 'getreflectionconstants', 'getreflectionconstant'];

    /** @var array<string, Node\Stmt\ClassLike> lowercase имя → объявление */
    private array $classes = [];

    /** @var array<string, true> классы, у которых есть наследники */
    private array $extended = [];

    /** @var list<string> стек lowercase имён объемлющих классов */
    private array $classStack = [];

    public function __construct(?ScopeStore $scopeStore = null)
    {
        $this->scopeStore = $scopeStore ?? ScopeStore::default();
    }

    #[\Override]
    public function beforeTraverse(array $nodes): ?array
    {
        parent::beforeTraverse($nodes);
        $this->classes    = [];
        $this->extended   = [];
        $this->classStack = [];

        foreach ($this->findNode(Node\Stmt\ClassLike::class) as $class) {
            if (!$class->name instanceof Node\Identifier) {
                continue;
            }

            $this->classes[strtolower($class->name->toString())] = $class;
            if ($class instanceof Node\Stmt\Class_ && $class->extends instanceof Node\Name) {
                $this->extended[strtolower($class->extends->toString())] = true;
            }

            foreach ($class->getConstants() as $const) {
                foreach ($const->consts as $item) {
                    $this->scopeStore->setConstToScope($item->name->toString(), $item->value);
                    $this->scopeStore->setConstToGlobal($item->name->toString(), $item->value);
                }
            }
        }

        return null;
    }

    public function enterNode(Node $node): int|Node|array|null
    {
        if ($node instanceof Node\Stmt\ClassLike) {
            $this->classStack[] = $node->name instanceof Node\Identifier ? strtolower($node->name->toString()) : '';

            return null;
        }

        if (!$node instanceof Node\Expr\ClassConstFetch || !$node->name instanceof Node\Identifier || !$node->class instanceof Node\Name) {
            return null;
        }

        $className = $node->class->toString();

        // ClassName::class → 'ClassName' (PHP pseudo-constant)
        if (strtolower($node->name->toString()) === 'class') {
            return in_array(strtolower($className), ['self', 'static', 'parent'], true) ? null : new Node\Scalar\String_($className);
        }

        $class = $this->resolveClass($className);
        if (!$class instanceof Node\Stmt\ClassLike) {
            return null;
        }

        [$value, $owner] = $this->lookup($class, $node->name->toString(), 0) ?? [null, null];
        if (!$value instanceof Node\Expr || $owner === null) {
            return null;
        }

        return $this->relocate($this->deepClone($value), $owner);
    }

    #[\Override]
    public function leaveNode(Node $node): int|Node|array|null
    {
        if ($node instanceof Node\Stmt\ClassLike) {
            array_pop($this->classStack);
        }

        return parent::leaveNode($node);
    }

    /**
     * Удаляет объявления констант, на которые не осталось ссылок (в том числе
     * строкой — `constant('X::Y')`).
     */
    #[\Override]
    public function afterTraverse(array $nodes): ?array
    {
        $used = [];
        foreach ($this->findNode(Node::class, $nodes) as $node) {
            // Имя константы собирается во время выполнения: constant('X::TYPE_' . $s), рефлексия
            if (
                $node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name
                && strtolower($node->name->toString()) === 'constant'
                && !($node->args[0] ?? null)?->value instanceof Node\Scalar\String_
                || ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\NullsafeMethodCall)
                && $node->name instanceof Node\Identifier
                && in_array(strtolower($node->name->toString()), self::REFLECTION_CONSTANT_METHODS, true)
            ) {
                return null;
            }

            if ($node instanceof Node\Expr\ClassConstFetch) {
                $used[$node->name instanceof Node\Identifier ? $node->name->toString() : '*'] = true;
            } elseif ($node instanceof Node\Scalar\String_) {
                $parts = explode('::', $node->value);
                $used[end($parts)] = true;
            }
        }

        if (isset($used['*']) || $this->hasPendingIncludes()) {
            return null;
        }

        foreach ($this->classes as $class) {
            foreach ($class->stmts as $i => $stmt) {
                if (!$stmt instanceof Node\Stmt\ClassConst) {
                    continue;
                }

                $stmt->consts = array_values(array_filter(
                    $stmt->consts,
                    static fn(Node\Const_ $item): bool => isset($used[$item->name->toString()]),
                ));
                if ($stmt->consts === []) {
                    unset($class->stmts[$i]);
                }
            }

            $class->stmts = array_values($class->stmts);
        }

        return null;
    }

    private function resolveClass(string $name): ?Node\Stmt\ClassLike
    {
        $lower   = strtolower($name);
        $current = end($this->classStack);
        if ($lower === 'self' || $lower === 'static') {
            if ($current === false || $current === '' || $lower === 'static' && isset($this->extended[$current])) {
                return null;
            }

            return $this->classes[$current] ?? null;
        }

        if ($lower === 'parent') {
            $class = $current !== false ? $this->classes[$current] ?? null : null;

            return $class instanceof Node\Stmt\Class_ && $class->extends instanceof Node\Name
                ? $this->classes[strtolower($class->extends->toString())] ?? null
                : null;
        }

        return $this->classes[strtolower(ltrim($name, '\\'))] ?? null;
    }

    /**
     * Значение константы и класс, где она объявлена; по цепочке родителей и интерфейсов.
     *
     * @return array{Node\Expr, string}|null
     */
    private function lookup(Node\Stmt\ClassLike $class, string $name, int $depth): ?array
    {
        if ($depth > 16) {
            return null;
        }

        foreach ($class->getConstants() as $const) {
            foreach ($const->consts as $item) {
                if ($item->name->toString() === $name) {
                    return [$item->value, (string) $class->name];
                }
            }
        }

        // case enum — объект, а не значение константы
        if ($class instanceof Node\Stmt\Enum_) {
            foreach ($class->stmts as $stmt) {
                if ($stmt instanceof Node\Stmt\EnumCase && $stmt->name->toString() === $name) {
                    return null;
                }
            }
        }

        $parents = match (true) {
            $class instanceof Node\Stmt\Class_ => [$class->extends, ...$class->implements],
            $class instanceof Node\Stmt\Interface_ => $class->extends,
            $class instanceof Node\Stmt\Enum_ => $class->implements,
            default => [],
        };
        foreach ($parents as $parent) {
            $declaration = $parent instanceof Node\Name ? $this->classes[strtolower($parent->toString())] ?? null : null;
            $found       = $declaration !== null ? $this->lookup($declaration, $name, $depth + 1) : null;
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /** self::/static:: в значении константы относятся к классу, где она объявлена. */
    private function relocate(Node\Expr $value, string $owner): Node\Expr
    {
        foreach ($this->findNode(Node\Expr\ClassConstFetch::class, $value) as $fetch) {
            if ($fetch->class instanceof Node\Name && in_array(strtolower($fetch->class->toString()), ['self', 'static'], true)) {
                $fetch->class = new Node\Name($owner);
            }
        }

        if (
            $value instanceof Node\Expr\ClassConstFetch
            && $value->class instanceof Node\Name
            && in_array(strtolower($value->class->toString()), ['self', 'static'], true)
        ) {
            $value->class = new Node\Name($owner);
        }

        return $value;
    }
}
