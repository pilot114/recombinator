<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;

/**
 * Подставляет литерал локальной переменной внутри функции или метода:
 *
 *   function createDriver($driver, $driverClass) {
 *       $driverClass = 'SQLite_Driver';
 *       return new $driverClass();
 *   }
 *   →
 *   function createDriver($driver, $driverClass) {
 *       return new ('SQLite_Driver')();
 *   }
 *
 * Переменной присвоен литерал (строка, число, true/false/null, константа класса) ровно
 * одной инструкцией верхнего уровня тела, других записей нет: ни присваивания, ни
 * ссылки, ни foreach/list/unset/global/static, ни передачи в вызов (может быть по
 * ссылке), ни `$$`, extract, compact, get_defined_vars. Подставляются чтения в
 * следующих инструкциях; чтения до присваивания (значение параметра) остаются.
 * Вложенные замыкания не трогаются — у них своя область видимости.
 * Присваивание, после которого чтений не осталось, удаляется.
 *
 * VarToScalarVisitor работает с кодом верхнего уровня; это правило — его аналог для тел.
 */
#[VisitorMeta('Подстановка литеральных локальных переменных в телах функций')]
class LocalConstantVisitor extends BaseVisitor
{
    /** @var array<string, true> переменные верхнего уровня, видимые из функций (`global $x`) */
    private array $globals = [];

    private bool $globalsArray = false;

    #[\Override]
    public function beforeTraverse(array $nodes): ?array
    {
        parent::beforeTraverse($nodes);
        $this->globals      = [];
        $this->globalsArray = false;
        foreach ($this->findNode(Node::class) as $node) {
            if ($node instanceof Node\Stmt\Global_) {
                foreach ($node->vars as $var) {
                    if ($var instanceof Node\Expr\Variable && is_string($var->name)) {
                        $this->globals[$var->name] = true;
                    }
                }
            } elseif ($node instanceof Node\Expr\Variable && $node->name === 'GLOBALS') {
                $this->globalsArray = true;
            }
        }

        // Код верхнего уровня — тоже последовательность инструкций (кроме глобальных, видимых из функций)
        if ($this->globalsArray || $this->dynamicScope($nodes)) {
            return null;
        }

        $stmts = array_values(array_filter($nodes, static fn(Node $n): bool => $n instanceof Node\Stmt));
        if (count($stmts) !== count($nodes)) {
            return null;
        }

        return $this->process($stmts, true);
    }

    public function enterNode(Node $node): ?Node
    {
        if (!$node instanceof Node\Stmt\Function_ && !$node instanceof Node\Stmt\ClassMethod && !$node instanceof Node\Expr\Closure) {
            return null;
        }

        $stmts = $node->stmts;
        if ($stmts === null || $this->dynamicScope($stmts)) {
            return null;
        }

        $result = $this->process($stmts, false);
        if ($result === null) {
            return null;
        }

        $node->stmts = $result;

        return $node;
    }

    /**
     * Одна переменная за вызов: подставляет её значение в последующие чтения.
     *
     * @param  list<Node\Stmt> $stmts
     * @return list<Node\Stmt>|null
     */
    private function process(array $stmts, bool $topLevel): ?array
    {
        foreach ($stmts as $index => $stmt) {
            $assign = $stmt instanceof Node\Stmt\Expression ? $stmt->expr : null;
            if (
                !$assign instanceof Node\Expr\Assign || !$assign->var instanceof Node\Expr\Variable || !is_string($assign->var->name)
                || $assign->var->name === 'this' || !$this->isLiteral($assign->expr)
            ) {
                continue;
            }

            $name = $assign->var->name;
            if ($this->writes($stmts, $name) !== 1 || $topLevel && isset($this->globals[$name])) {
                continue;
            }

            $after   = array_slice($stmts, $index + 1, null, true);
            $replace = $this->replaceReads($after, $name, $assign->expr);
            foreach ($replace as $position => $newStmt) {
                $stmts[$position] = $newStmt;
            }

            $changed = $replace !== array_slice($stmts, $index + 1, null, true);
            if ($this->reads(array_slice($stmts, $index + 1), $name) === 0) {
                unset($stmts[$index]);
                $changed = true;
            }

            if ($changed) {
                return array_values($stmts); // по одной переменной за обход: позиции изменились
            }
        }

        return null;
    }

    private function isLiteral(Node\Expr $expr): bool
    {
        return match (true) {
            $expr instanceof Node\Scalar\String_, $expr instanceof Node\Scalar\Int_, $expr instanceof Node\Scalar\Float_ => true,
            $expr instanceof Node\Expr\ConstFetch => in_array($expr->name->toLowerString(), ['true', 'false', 'null'], true),
            $expr instanceof Node\Expr\ClassConstFetch => $expr->class instanceof Node\Name && $expr->name instanceof Node\Identifier
                && $expr->class->toLowerString() !== 'static',
            // Массив подставляется только поэлементно ($v['a']['b']), целиком не копируется
            $expr instanceof Node\Expr\Array_ => array_all($expr->items, fn(Node\ArrayItem $i): bool => !$i->byRef && !$i->unpack
                && (!$i->key instanceof \PhpParser\Node\Expr || $i->key instanceof Node\Scalar\String_ || $i->key instanceof Node\Scalar\Int_)
                && ($this->isLiteral($i->value) || $this->isConstantConcat($i->value))),
            default => false,
        };
    }

    private function isConstantConcat(Node\Expr $expr): bool
    {
        return $expr instanceof Node\Expr\BinaryOp\Concat
            && ($this->isLiteral($expr->left) || $this->isConstantConcat($expr->left) || $expr->left instanceof Node\Expr\ConstFetch)
            && ($this->isLiteral($expr->right) || $this->isConstantConcat($expr->right) || $expr->right instanceof Node\Expr\ConstFetch);
    }

    /** Элемент литерального массива по цепочке литеральных ключей; null — не найден. */
    public function element(Node\Expr\Array_ $array, array $keys): ?Node\Expr
    {
        $current = $array;
        foreach ($keys as $key) {
            if (!$current instanceof Node\Expr\Array_) {
                return null;
            }

            $found = null;
            foreach ($current->items as $item) {
                if (($item->key instanceof Node\Scalar\String_ || $item->key instanceof Node\Scalar\Int_) && $item->key->value === $key) {
                    $found = $item->value;
                }
            }

            if ($found === null) {
                return null;
            }

            $current = $found;
        }

        return $current;
    }

    /** `$$x`, extract(), compact(), get_defined_vars(), func_get_args() — переменные видны иначе. */
    private function dynamicScope(array $stmts): bool
    {
        foreach ($this->own($stmts) as $node) {
            if ($node instanceof Node\Expr\Variable && !is_string($node->name)) {
                return true;
            }

            if (
                $node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name
                && in_array($node->name->toLowerString(), ['extract', 'compact', 'get_defined_vars', 'parse_str'], true)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Сколько раз переменная может быть записана (присваивание считается одной записью;
     * всё, что может записать неявно, — бесконечно).
     *
     * @param array<Node\Stmt> $stmts
     */
    private function writes(array $stmts, string $name): int
    {
        $count = 0;
        foreach ($this->own($stmts) as $node) {
            $targets = match (true) {
                $node instanceof Node\Expr\Assign, $node instanceof Node\Expr\AssignOp, $node instanceof Node\Expr\PreInc,
                $node instanceof Node\Expr\PreDec, $node instanceof Node\Expr\PostInc, $node instanceof Node\Expr\PostDec => [$node->var],
                $node instanceof Node\Expr\AssignRef => [$node->var, $node->expr],
                $node instanceof Node\Stmt\Foreach_ => [$node->keyVar, $node->valueVar],
                $node instanceof Node\Stmt\Unset_, $node instanceof Node\Stmt\Global_ => $node->vars,
                $node instanceof Node\Stmt\Static_ => array_map(static fn(Node\StaticVar $v): Node => $v->var, $node->vars),
                $node instanceof Node\Stmt\Catch_ => [$node->var],
                $node instanceof Node\Expr\List_ => array_map(static fn(?Node\ArrayItem $i): ?Node => $i?->value, $node->items),
                $node instanceof Node\Expr\ClosureUse => $node->byRef ? [$node->var] : [],
                $node instanceof Node\Arg => [$node->value],
                default => [],
            };

            foreach ($targets as $target) {
                // Запись в элемент или свойство меняет саму переменную
                $base = $target;
                while ($base instanceof Node\Expr\ArrayDimFetch || $base instanceof Node\Expr\PropertyFetch) {
                    $base = $base->var;
                }

                if (!$base instanceof Node\Expr\Variable || $base->name !== $name) {
                    continue;
                }

                if ($base !== $target && !$node instanceof Node\Arg) {
                    return PHP_INT_MAX;
                }

                // Передача целиком в вызов — возможно, по ссылке
                if ($base === $target && ($node instanceof Node\Arg || !$node instanceof Node\Expr\Assign)) {
                    return PHP_INT_MAX;
                }

                if ($base !== $target) {
                    continue; // элемент в аргументе — значение, не ссылка на переменную
                }

                $count++;
            }
        }

        return $count;
    }

    /** @param array<Node> $stmts */
    private function reads(array $stmts, string $name): int
    {
        return count(array_filter(
            $this->own($stmts),
            static fn(Node $n): bool => $n instanceof Node\Expr\Variable && $n->name === $name || $n instanceof Node\ClosureUse && $n->var->name === $name,
        ));
    }

    /**
     * Узлы тела без вложенных функций и замыканий (стрелочные функции — внутри: они
     * захватывают значение при создании).
     *
     * @param  array<Node> $stmts
     * @return list<Node>
     */
    private function own(array $stmts): array
    {
        $found = [];
        new NodeTraverser(new class ($found) extends NodeVisitorAbstract {
            /** @param list<Node> $found */
            public function __construct(private array &$found)
            {
            }

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof Node\Stmt\Function_ || $node instanceof Node\Stmt\ClassLike || $node instanceof Node\Stmt\ClassMethod) {
                    return NodeVisitor::DONT_TRAVERSE_CHILDREN;
                }

                $this->found[] = $node;

                // Замыкание: свои переменные; захват use() — видим (как запись по ссылке или чтение)
                if ($node instanceof Node\Expr\Closure) {
                    array_push($this->found, ...$node->uses);

                    return NodeVisitor::DONT_TRAVERSE_CHILDREN;
                }

                return null;
            }
        })->traverse($stmts);

        return $found;
    }

    /**
     * @param  array<int, Node\Stmt> $stmts
     * @return array<int, Node\Stmt>
     */
    private function replaceReads(array $stmts, string $name, Node\Expr $value): array
    {
        $result = [];
        foreach ($stmts as $position => $stmt) {
            $result[$position] = new NodeTraverser(new class ($name, $value, $this) extends NodeVisitorAbstract {
                public function __construct(private readonly string $name, private readonly Node\Expr $value, private readonly LocalConstantVisitor $owner)
                {
                }

                public function enterNode(Node $node): ?int
                {
                    // Замыкание: use ($x) — переменная, не значение; тело — своя область
                    return $node instanceof Node\Expr\Closure || $node instanceof Node\Stmt\Function_ || $node instanceof Node\Stmt\ClassLike
                        ? NodeVisitor::DONT_TRAVERSE_CHILDREN
                        : null;
                }

                public function leaveNode(Node $node): ?Node
                {
                    if ($this->value instanceof Node\Expr\Array_) {
                        return $this->element($node);
                    }

                    if (!$node instanceof Node\Expr\Variable || $node->name !== $this->name) {
                        return null;
                    }

                    $parent = $node->getAttribute('parent');
                    if ($parent instanceof Node\Expr\Isset_ || $parent instanceof Node\Expr\Empty_ || $parent instanceof Node\Expr\ArrayDimFetch && $parent->var === $node) {
                        return null; // isset/empty требуют переменную; $x[...] на строке оставим как есть
                    }

                    return $this->owner->copy($this->value);
                }

                /** `$v['a']['b']` по литеральному массиву → значение элемента (не цель записи, не isset). */
                private function element(Node $node): ?Node
                {
                    if (!$node instanceof Node\Expr\ArrayDimFetch || !$this->value instanceof Node\Expr\Array_) {
                        return null;
                    }

                    $parent = $node->getAttribute('parent');
                    if ($parent instanceof Node\Expr\ArrayDimFetch && $parent->var === $node || $parent instanceof Node\Expr\Isset_ || $parent instanceof Node\Expr\Empty_) {
                        return null; // внешнее звено цепочки обработает себя само
                    }

                    $keys = [];
                    $base = $node;
                    while ($base instanceof Node\Expr\ArrayDimFetch) {
                        if (!$base->dim instanceof Node\Scalar\String_ && !$base->dim instanceof Node\Scalar\Int_) {
                            return null;
                        }

                        array_unshift($keys, $base->dim->value);
                        $base = $base->var;
                    }

                    if (!$base instanceof Node\Expr\Variable || $base->name !== $this->name) {
                        return null;
                    }

                    $value = $this->owner->element($this->value, $keys);

                    return $value instanceof \PhpParser\Node\Expr ? $this->owner->copy($value) : null;
                }
            })->traverse([$stmt])[0];
        }

        return array_filter($result, static fn(Node $n): bool => $n instanceof Node\Stmt);
    }

    public function copy(Node\Expr $expr): Node\Expr
    {
        $clone = $this->deepClone($expr);
        assert($clone instanceof Node\Expr);

        return $clone;
    }
}
