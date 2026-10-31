<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;
use Recombinator\Transformation\BodyInliner;

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
 * Переменной присвоен литерал (строка, число, true/false/null, константа класса)
 * инструкцией верхнего уровня тела. Значение держится до следующей инструкции с записью
 * (присваивание, foreach/list/unset, передача в вызов — может быть по ссылке):
 * подставляются чтения в инструкциях между ними и в той части инструкции с записью,
 * что вычисляется раньше записи (условие if, правая часть `$v = …`). Так сворачивается
 * и код, встроенный CallSiteInlinerVisitor:
 *
 *   $driver = 'pdo_sqlite'; if ($driver !== null) { $driver = (string) $driver; }
 *
 * Не подставляется при ссылках на переменную (`&`, global, static, use по ссылке),
 * передаче её в вызов до присваивания, `goto`, `$$`, extract, compact, get_defined_vars.
 * Чтения до присваивания (значение параметра) остаются. Вложенные замыкания не
 * трогаются — у них своя область видимости. Отсутствующий ключ литерального массива
 * в `$v['k'] ?? …` — null. Присваивание, после которого чтений не осталось (или их нет
 * до перезаписи), удаляется.
 *
 * VarToScalarVisitor работает с кодом верхнего уровня; это правило — его аналог для тел.
 */
#[VisitorMeta('Подстановка литеральных локальных переменных в телах функций', targets: [Node\Expr\Assign::class])]
class LocalConstantVisitor extends BaseVisitor
{
    /** @var array<string, true> переменные верхнего уровня, видимые из функций (`global $x`) */
    private array $globals = [];

    private bool $globalsArray = false;

    /** @var array<string, Node\Stmt\Function_|null> функции программы по lowercase имени; null — объявлена не один раз */
    private array $functions = [];

    /** @var array<string, list<Node\Stmt\ClassMethod>> методы программы по lowercase имени */
    private array $methods = [];

    #[\Override]
    public function beforeTraverse(array $nodes): ?array
    {
        parent::beforeTraverse($nodes);
        $this->globals      = [];
        $this->globalsArray = false;
        $this->functions    = [];
        $this->methods      = [];
        foreach ($this->findNode(Node::class) as $node) {
            if ($node instanceof Node\Stmt\Function_) {
                $key                   = $node->name->toLowerString();
                $this->functions[$key] = array_key_exists($key, $this->functions) ? null : $node;
            } elseif ($node instanceof Node\Stmt\ClassMethod) {
                $this->methods[$node->name->toLowerString()][] = $node;
            } elseif ($node instanceof Node\Stmt\Global_) {
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
            if ($topLevel && isset($this->globals[$name]) || $this->aliased($stmts, $index, $name)) {
                continue;
            }

            // Значение держится до первой инструкции с записью (или передачей в вызов — возможно, по ссылке)
            $end = count($stmts);
            foreach (array_slice($stmts, $index + 1, null, true) as $position => $later) {
                if ($this->writes([$later], $name) > 0) {
                    $end = $position;
                    break;
                }
            }

            if ($end < count($stmts) && $this->hasGoto($stmts)) {
                continue; // goto может вернуть выполнение за запись
            }

            $range   = array_slice($stmts, $index + 1, $end - $index - 1, true);
            $before  = $this->reads($range, $name);
            $replace = $this->replaceReads($range, $name, $assign->expr);
            foreach ($replace as $position => $newStmt) {
                $stmts[$position] = $newStmt;
            }

            $range   = array_slice($stmts, $index + 1, $end - $index - 1, true);
            $changed = $this->reads($range, $name) < $before;

            // В инструкции с записью значение видно там, где она вычисляется раньше записи
            $writer = $stmts[$end] ?? null;
            $head   = match (true) {
                $writer instanceof Node\Stmt\If_ => $writer->cond,
                $writer instanceof Node\Stmt\Expression && $writer->expr instanceof Node\Expr\Assign
                    && $writer->expr->var instanceof Node\Expr\Variable && $writer->expr->var->name === $name => $writer->expr->expr,
                default => null,
            };
            if ($head instanceof Node\Expr && $this->writes([$head], $name) === 0 && $this->reads([$head], $name) > 0) {
                $head = $this->replaceReads([$head], $name, $assign->expr)[0];
                if ($writer instanceof Node\Stmt\If_) {
                    $writer->cond = $head;
                } else {
                    assert($writer instanceof Node\Stmt\Expression && $writer->expr instanceof Node\Expr\Assign);
                    $writer->expr->expr = $head;
                }

                $changed = $changed || $this->reads([$head], $name) === 0;
            }

            // Присваивание без чтений: дальше нет ни одного, или их нет до перезаписи `$v = …`
            $overwritten = $writer instanceof Node\Stmt\Expression && $head instanceof Node\Expr;
            if (
                $this->reads(array_slice($stmts, $index + 1), $name) === 0
                || $overwritten && $this->reads($range, $name) === 0 && $this->reads([$head], $name) === 0
            ) {
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

    /**
     * Переменная может измениться не своей записью: ссылка на неё (`&`, global, static,
     * use по ссылке) или передача в вызов до присваивания — вызов мог сохранить ссылку.
     *
     * @param array<int, Node\Stmt> $stmts
     */
    private function aliased(array $stmts, int $index, string $name): bool
    {
        $is = static function (?Node $node) use ($name): bool {
            while ($node instanceof Node\Expr\ArrayDimFetch || $node instanceof Node\Expr\PropertyFetch) {
                $node = $node->var;
            }

            return $node instanceof Node\Expr\Variable && $node->name === $name;
        };

        foreach ($this->own($stmts) as $node) {
            $aliased = match (true) {
                $node instanceof Node\Expr\AssignRef => $is($node->var) || $is($node->expr),
                $node instanceof Node\Stmt\Global_ => array_any($node->vars, $is),
                $node instanceof Node\Stmt\Static_ => array_any($node->vars, static fn(Node\StaticVar $v): bool => $is($v->var)),
                $node instanceof Node\Expr\ClosureUse => $node->byRef && $is($node->var),
                $node instanceof Node\Stmt\Foreach_ => $node->byRef && $is($node->valueVar),
                // `['k' => &$v]`, `[&$v] = …`: элемент массива — ссылка на переменную
                $node instanceof Node\ArrayItem => $node->byRef && $is($node->value),
                default => false,
            };
            if ($aliased) {
                return true;
            }
        }

        return array_any(
            $this->own(array_slice($stmts, 0, $index)),
            static fn(Node $node): bool => $node instanceof Node\Arg && $node->value instanceof Node\Expr\Variable && $node->value->name === $name,
        );
    }

    /**
     * Аргумент с этой позицией вызов принимает по значению: встроенная функция — по сигнатуре,
     * своя — по объявлению, метод и конструктор — по всем одноимённым методам программы.
     */
    private function passedByValue(Node\Expr\CallLike $call, int $position): bool
    {
        if ($call instanceof Node\Expr\FuncCall) {
            $name = $call->name instanceof Node\Name ? strtolower(ltrim($call->name->toString(), '\\')) : null;
            if ($name === null || !array_key_exists($name, $this->functions)) {
                return !BodyInliner::mayPassByRef($call, $position);
            }

            $function = $this->functions[$name];

            // Объявлена в нескольких местах — какая вызовется, неизвестно
            return $function instanceof Node\Stmt\Function_ && self::byValue($function->params, $position);
        }

        $name = match (true) {
            $call instanceof Node\Expr\New_ => '__construct',
            $call instanceof Node\Expr\MethodCall, $call instanceof Node\Expr\NullsafeMethodCall, $call instanceof Node\Expr\StaticCall
                => $call->name instanceof Node\Identifier ? $call->name->toLowerString() : null,
            default => null,
        };
        $methods = $name === null ? [] : $this->methods[$name] ?? [];

        // Нет такого метода в программе — метод встроенного класса, сигнатура неизвестна
        return $methods !== [] && array_all($methods, static fn(Node\Stmt\ClassMethod $m): bool => self::byValue($m->params, $position));
    }

    /** @param array<Node\Param> $params */
    private static function byValue(array $params, int $position): bool
    {
        $last  = end($params);
        $param = $params[$position] ?? ($last !== false && $last->variadic ? $last : null);

        return !$param?->byRef; // лишний аргумент без вариативного параметра — по значению
    }

    /** @param array<int, Node\Stmt> $stmts */
    private function hasGoto(array $stmts): bool
    {
        return array_any($this->own($stmts), static fn(Node $n): bool => $n instanceof Node\Stmt\Goto_);
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
     * @param array<Node> $stmts
     */
    private function writes(array $stmts, string $name): int
    {
        $count   = 0;
        $byValue = new \SplObjectStorage();
        foreach ($this->own($stmts) as $node) {
            // Аргумент, который вызов принимает по значению (sprintf, своя функция или метод без &), не запись
            if ($node instanceof Node\Expr\CallLike && !$node->isPartialFunctionApplication()) {
                foreach (array_values($node->args) as $i => $arg) {
                    if (
                        $arg instanceof Node\Arg && !$arg->unpack && !$arg->name instanceof Node\Identifier
                        && $this->passedByValue($node, $i)
                    ) {
                        $byValue[$arg] = true;
                    }
                }
            }

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
                $node instanceof Node\Arg => isset($byValue[$node]) ? [] : [$node->value],
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

                // Запись в элемент, передача (целиком или элементом) в вызов, возможно по ссылке, неявная запись
                if ($base !== $target || $node instanceof Node\Arg || !$node instanceof Node\Expr\Assign) {
                    return PHP_INT_MAX;
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
     * @template T of Node
     * @param  array<int, T> $nodes инструкции или выражения
     * @return array<int, T>
     */
    private function replaceReads(array $nodes, string $name, Node\Expr $value): array
    {
        $result = [];
        foreach ($nodes as $position => $stmt) {
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

                    // В "...$x..." скаляр — часть строки, иначе принтер выведет "{'>>'}" как текст
                    if ($parent instanceof Node\Scalar\InterpolatedString) {
                        return $this->value instanceof Node\Scalar\String_ || $this->value instanceof Node\Scalar\Int_
                            ? new Node\InterpolatedStringPart((string) $this->value->value)
                            : null;
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
                    if ($value instanceof Node\Expr) {
                        return $this->owner->copy($value);
                    }

                    // Отсутствующий ключ: null только там, где чтение не выдаёт предупреждение
                    return $parent instanceof Node\Expr\BinaryOp\Coalesce && $parent->left === $node && $this->owner->missing($this->value, $keys)
                        ? new Node\Expr\ConstFetch(new Node\Name('null'))
                        : null;
                }
            })->traverse([$stmt])[0];
        }

        /** @var array<int, T> $result */
        return $result;
    }

    /**
     * Ключа нет в литеральном массиве: на каком-то уровне массив с явными ключами, и ни
     * один не совпадает (сравнение строками: '1' и 1 — один ключ).
     *
     * @param list<int|string> $keys
     */
    public function missing(Node\Expr\Array_ $array, array $keys): bool
    {
        $current = $array;
        foreach ($keys as $key) {
            if (!$current instanceof Node\Expr\Array_) {
                return false;
            }

            $found = null;
            foreach ($current->items as $item) {
                if (!$item->key instanceof Node\Scalar\String_ && !$item->key instanceof Node\Scalar\Int_) {
                    return false; // ключ без имени (список) или вычисляемый
                }

                if ((string) $item->key->value === (string) $key) {
                    $found = $item->value;
                }
            }

            if (!$found instanceof Node\Expr) {
                return true;
            }

            $current = $found;
        }

        return false;
    }

    public function copy(Node\Expr $expr): Node\Expr
    {
        $clone = $this->deepClone($expr);
        assert($clone instanceof Node\Expr);

        return $clone;
    }
}
