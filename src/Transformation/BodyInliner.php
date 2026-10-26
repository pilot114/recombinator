<?php

declare(strict_types=1);

namespace Recombinator\Transformation;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\NodeVisitorAbstract;

/**
 * Встраивание тела метода/замыкания на место вызова-оператора.
 *
 * Тело переносится в область видимости вызывающего кода:
 *  - параметры связываются с аргументами: переменная-аргумент подставляется напрямую,
 *    если параметр в теле не перезаписывается, иначе аргумент вычисляется во временную
 *    переменную (порядок вычисления аргументов сохраняется);
 *  - локальные переменные получают уникальные имена ($prefix_name_N);
 *  - $this заменяется выражением объекта;
 *  - вложенные замыкания — своя область видимости: в них меняются только захваченные
 *    через use переменные, в стрелочных функциях — свободные переменные.
 */
final class BodyInliner
{
    private const array SUPERGLOBALS = ['GLOBALS', '_SERVER', '_GET', '_POST', '_FILES', '_COOKIE', '_SESSION', '_REQUEST', '_ENV', 'this'];

    private const array DYNAMIC_SCOPE_FUNCTIONS = ['compact', 'extract', 'get_defined_vars', 'func_get_args', 'func_get_arg', 'func_num_args'];

    /**
     * Встраивает тело: возвращает операторы связывания аргументов и тело в области
     * вызывающего кода, либо null, если безопасно встроить нельзя.
     *
     * @param  array<Node\Param>                                         $params
     * @param  array<Node\Arg|Node\VariadicPlaceholder|Node\ArgPlaceholder> $args
     * @param  array<Node\Stmt>                                          $body
     * @param  Node\Expr\Variable|null                                   $thisVar   на что заменить $this
     * @param  array<string, true>                                       $callerVars имена, занятые в вызывающем коде;
     *                                                                               дополняется новыми именами
     * @param  array<string, true>                                       $outerVars  переменные вызывающего кода, на которые
     *                                                                               тело уже ссылается (не переименовываются)
     * @return array<Node\Stmt>|null
     */
    public static function inline(
        array $params,
        array $args,
        array $body,
        string $prefix,
        ?Node\Expr\Variable $thisVar,
        string $className = '',
        array &$callerVars = [],
        array $outerVars = [],
    ): ?array {
        if (!self::isInlinableBody($body)) {
            return null;
        }

        $written = self::writtenVars($body);
        $locals  = array_diff_key(self::ownScopeVars($body), $outerVars);
        foreach ($params as $param) {
            if ($param->var instanceof Node\Expr\Variable && is_string($param->var->name)) {
                $locals[$param->var->name] = true;
            }
        }

        // Наименьший суффикс, при котором ни одно новое имя не занято: результат
        // зависит только от кода, а не от истории процесса (прогон можно продолжить с чекпоинта)
        $counter = 0;
        do {
            $suffix = '_' . (++$counter);
            $free   = array_all(array_keys($locals), static fn(string $n): bool => !isset($callerVars[$prefix . '_' . $n . $suffix]));
        } while (!$free);

        foreach (array_keys($locals) as $name) {
            $callerVars[$prefix . '_' . $name . $suffix] = true;
        }

        $map     = [];
        $prelude = [];

        $argByName = [];
        foreach ($args as $arg) {
            if (!$arg instanceof Node\Arg || $arg->unpack || $arg->byRef) {
                return null;
            }

            if ($arg->name instanceof Node\Identifier) {
                $argByName[$arg->name->toString()] = $arg->value;
            }
        }

        // Если аргумент присваивает что-то сам, прямая подстановка переменных-аргументов
        // могла бы прочитать их не в том порядке
        $argsHaveWrites = array_any($args, static fn(Node\Arg $a): bool => self::hasWrites($a->value));

        foreach ($params as $i => $param) {
            if ($param->byRef || $param->variadic || !$param->var instanceof Node\Expr\Variable || !is_string($param->var->name)) {
                return null;
            }

            $name       = $param->var->name;
            $positional = $args[$i] ?? null;
            $value      = $argByName[$name]
                ?? ($positional instanceof Node\Arg && !$positional->name instanceof Node\Identifier ? $positional->value : null)
                ?? $param->default
                ?? new Node\Expr\ConstFetch(new Node\Name('null'));

            $isPlainVar = $value instanceof Node\Expr\Variable && is_string($value->name) && !in_array($value->name, self::SUPERGLOBALS, true);
            $isThis     = $value instanceof Node\Expr\Variable && $value->name === 'this' && $thisVar instanceof Node\Expr\Variable;
            if (!isset($written[$name]) && !$argsHaveWrites && ($isPlainVar || $isThis || self::isConstant($value))) {
                $map[$name] = $isThis ? $thisVar : $value;
                continue;
            }

            $tmp        = new Node\Expr\Variable($prefix . '_' . $name . $suffix);
            $prelude[]  = new Node\Stmt\Expression(new Node\Expr\Assign($tmp, self::clone($value)));
            $map[$name] = $tmp;
        }

        // Локальные переменные тела — новые имена, чтобы не пересечься с вызывающим кодом
        foreach (array_keys($locals) as $name) {
            $map[$name] ??= new Node\Expr\Variable($prefix . '_' . $name . $suffix);
        }

        $rewritten = self::substitute($body, $map, $thisVar, $className);

        return $rewritten === null ? null : [...$prelude, ...$rewritten];
    }

    /**
     * Тело можно встроить как последовательность операторов: нет return в собственной
     * области, нет yield, динамического доступа к переменным и parent::.
     *
     * @param array<Node\Stmt> $body
     */
    public static function isInlinableBody(array $body): bool
    {
        $ok = true;
        self::walkOwnScope($body, static function (Node $node) use (&$ok): void {
            if (
                $node instanceof Node\Stmt\Return_
                || $node instanceof Node\Expr\Yield_
                || $node instanceof Node\Expr\YieldFrom
                || ($node instanceof Node\Expr\Variable && !is_string($node->name))
                || ($node instanceof Node\Stmt\Global_)
                || ($node instanceof Node\Stmt\Static_)
                || (
                    ($node instanceof Node\Expr\StaticCall
                        || $node instanceof Node\Expr\StaticPropertyFetch
                        || $node instanceof Node\Expr\ClassConstFetch)
                    && $node->class instanceof Node\Name
                    && strtolower($node->class->toString()) === 'parent'
                )
                || (
                    $node instanceof Node\Expr\FuncCall
                    && $node->name instanceof Node\Name
                    && in_array(strtolower($node->name->getLast()), self::DYNAMIC_SCOPE_FUNCTIONS, true)
                )
            ) {
                $ok = false;
            }
        });

        return $ok;
    }

    /**
     * Имена переменных собственной области видимости (без суперглобальных и $this).
     *
     * @param  array<Node> $nodes
     * @return array<string, true>
     */
    public static function ownScopeVars(array $nodes): array
    {
        $names = [];
        self::walkOwnScope($nodes, static function (Node $node) use (&$names): void {
            if ($node instanceof Node\Expr\Variable && is_string($node->name) && !in_array($node->name, self::SUPERGLOBALS, true)) {
                $names[$node->name] = true;
            }
        });

        return $names;
    }

    /**
     * Переменные, которые тело перезаписывает в собственной области видимости.
     *
     * @param  array<Node> $nodes
     * @return array<string, true>
     */
    public static function writtenVars(array $nodes): array
    {
        $names = [];
        $mark  = static function (?Node $target) use (&$names, &$mark): void {
            if ($target instanceof Node\Expr\Variable && is_string($target->name)) {
                $names[$target->name] = true;
            } elseif ($target instanceof Node\Expr\ArrayDimFetch || $target instanceof Node\Expr\PropertyFetch) {
                // $a[] = 1 / $a->x = 1 меняют значение $a (для массивов — копию)
                $mark($target->var);
            } elseif ($target instanceof Node\Expr\List_ || $target instanceof Node\Expr\Array_) {
                foreach ($target->items as $item) {
                    $mark($item?->value);
                }
            }
        };

        self::walkOwnScope($nodes, static function (Node $node) use ($mark): void {
            $targets = match (true) {
                $node instanceof Node\Expr\Assign,
                $node instanceof Node\Expr\AssignOp,
                $node instanceof Node\Expr\AssignRef,
                $node instanceof Node\Expr\PreInc,
                $node instanceof Node\Expr\PreDec,
                $node instanceof Node\Expr\PostInc,
                $node instanceof Node\Expr\PostDec => [$node->var],
                $node instanceof Node\Stmt\Unset_ => $node->vars,
                $node instanceof Node\Stmt\Foreach_ => [$node->keyVar, $node->valueVar],
                $node instanceof Node\Stmt\Catch_ => [$node->var],
                // Аргумент функции может передаваться по ссылке (sort($a), preg_match(..., $m));
                // by-ref параметры пользовательских методов с переприсваиванием — редкость, их не учитываем
                $node instanceof Node\Expr\FuncCall => array_map(
                    static fn(int $i, $arg): ?Node => $arg instanceof Node\Arg && self::mayPassByRef($node, $i) ? $arg->value : null,
                    array_keys($node->args),
                    $node->args
                ),
                default => [],
            };
            foreach ($targets as $target) {
                $mark($target);
            }
        });

        return $names;
    }

    /**
     * Значение, которое можно копировать без изменения семантики: литералы,
     * константы, массивы из них и замыкания без захвата и $this.
     */
    public static function isConstant(?Node $node): bool
    {
        return match (true) {
            $node instanceof Node\Scalar\Int_, $node instanceof Node\Scalar\Float_, $node instanceof Node\Scalar\String_ => true,
            $node instanceof Node\Expr\ConstFetch => true,
            $node instanceof Node\Expr\ClassConstFetch => $node->class instanceof Node\Name && $node->name instanceof Node\Identifier,
            $node instanceof Node\Expr\UnaryMinus, $node instanceof Node\Expr\UnaryPlus,
            $node instanceof Node\Expr\BooleanNot, $node instanceof Node\Expr\BitwiseNot,
            $node instanceof Node\Expr\Cast => self::isConstant($node->expr),
            // Операции над константами чистые и при повторном вычислении дают то же самое
            $node instanceof Node\Expr\BinaryOp => self::isConstant($node->left) && self::isConstant($node->right),
            $node instanceof Node\Expr\Ternary => self::isConstant($node->cond)
                && (!$node->if instanceof \PhpParser\Node\Expr || self::isConstant($node->if)) && self::isConstant($node->else),
            $node instanceof Node\Expr\Array_ => array_all(
                $node->items,
                static fn(Node\ArrayItem $i): bool => !$i->byRef && !$i->unpack
                    && (!$i->key instanceof \PhpParser\Node\Expr || self::isConstant($i->key)) && self::isConstant($i->value)
            ),
            $node instanceof Node\Expr\Closure => $node->uses === [] && ($node->static || !self::usesThis($node->stmts)),
            default => false,
        };
    }

    /** Глубокая копия узла. */
    public static function clone(Node\Expr $expr): Node\Expr
    {
        [$copy] = new NodeTraverser(new CloningVisitor())->traverse([$expr]);
        assert($copy instanceof Node\Expr);

        return $copy;
    }

    /**
     * Подставляет переменные по карте с учётом областей видимости вложенных функций.
     *
     * @param  array<Node\Stmt>              $stmts
     * @param  array<string, Node\Expr>      $map
     * @return array<Node\Stmt>|null  null — захваченную переменную нельзя заменить выражением
     */
    public static function substitute(array $stmts, array $map, ?Node\Expr\Variable $thisVar, string $className = ''): ?array
    {
        $visitor = new class ($map, $thisVar, $className) extends NodeVisitorAbstract {
            public bool $failed = false;

            /** Глубина интерполированных строк: внутри можно подставить только переменную */
            private int $interpolation = 0;

            /** @var list<array<string, Node\Expr>|null> карты областей; null — область без подстановок */
            private array $scopes;

            /** @param array<string, Node\Expr> $map */
            public function __construct(array $map, private readonly ?Node\Expr\Variable $thisVar, private readonly string $className)
            {
                $this->scopes = [$map];
            }

            public function enterNode(Node $node): ?int
            {
                $map = end($this->scopes);
                if ($node instanceof Node\Scalar\InterpolatedString) {
                    $this->interpolation++;
                }

                if ($node instanceof Node\Expr\Closure) {
                    if (!$node->static && $this->thisVar instanceof Node\Expr\Variable && BodyInliner::usesThis($node->stmts)) {
                        $this->failed = true;
                        return NodeVisitor::STOP_TRAVERSAL;
                    }

                    // В теле замыкания видны только захваченные переменные, под старыми именами
                    $inner = [];
                    foreach ($node->uses as $use) {
                        if (!is_string($use->var->name) || !is_array($map) || !isset($map[$use->var->name])) {
                            continue;
                        }

                        $target = $map[$use->var->name];
                        if (!$target instanceof Node\Expr\Variable || !is_string($target->name)) {
                            $this->failed = true;
                            return NodeVisitor::STOP_TRAVERSAL;
                        }

                        $inner[$use->var->name] = $target;
                        $use->var = new Node\Expr\Variable($target->name);
                    }

                    // Параметры замыкания и его локальные переменные затеняют внешние
                    foreach ($node->params as $param) {
                        if ($param->var instanceof Node\Expr\Variable && is_string($param->var->name)) {
                            unset($inner[$param->var->name]);
                        }
                    }

                    $this->scopes[] = $inner;
                    return null;
                }

                if ($node instanceof Node\Expr\ArrowFunction) {
                    // Стрелочная функция захватывает свободные переменные по значению при создании
                    $inner = is_array($map) ? $map : [];
                    foreach ($node->params as $param) {
                        if ($param->var instanceof Node\Expr\Variable && is_string($param->var->name)) {
                            unset($inner[$param->var->name]);
                        }
                    }

                    foreach ($inner as $target) {
                        if (!$target instanceof Node\Expr\Variable && !BodyInliner::isConstant($target)) {
                            $this->failed = true;
                            return NodeVisitor::STOP_TRAVERSAL;
                        }
                    }

                    $this->scopes[] = $inner;
                    return null;
                }

                if ($node instanceof Node\FunctionLike || $node instanceof Node\Stmt\ClassLike) {
                    $this->scopes[] = null;
                    return null;
                }

                if (
                    $this->className !== ''
                    && ($node instanceof Node\Expr\New_ || $node instanceof Node\Expr\StaticCall
                        || $node instanceof Node\Expr\StaticPropertyFetch || $node instanceof Node\Expr\ClassConstFetch
                        || $node instanceof Node\Expr\Instanceof_)
                    && $node->class instanceof Node\Name
                    && in_array(strtolower($node->class->toString()), ['self', 'static'], true)
                    && $map !== null
                ) {
                    $node->class = new Node\Name($this->className);
                }

                return null;
            }

            public function leaveNode(Node $node): int|Node|null
            {
                if ($node instanceof Node\Scalar\InterpolatedString) {
                    $this->interpolation--;
                }

                if ($node instanceof Node\FunctionLike || $node instanceof Node\Stmt\ClassLike) {
                    array_pop($this->scopes);
                    return null;
                }

                $map = end($this->scopes);
                if (!$node instanceof Node\Expr\Variable || !is_string($node->name) || !is_array($map)) {
                    return null;
                }

                if ($node->name === 'this') {
                    // Стрелочные функции привязывают $this автоматически; в обычных функциях и классах — свой $this
                    return $this->thisVar instanceof Node\Expr\Variable && is_string($this->thisVar->name) && !in_array(null, $this->scopes, true)
                        ? new Node\Expr\Variable($this->thisVar->name)
                        : null;
                }

                if (!isset($map[$node->name])) {
                    return null;
                }

                // "{implode(...)}" в строке — не выражение, а текст
                if ($this->interpolation > 0 && !$map[$node->name] instanceof Node\Expr\Variable) {
                    $this->failed = true;
                    return NodeVisitor::STOP_TRAVERSAL;
                }

                return BodyInliner::clone($map[$node->name]);
            }
        };

        $copies = [];
        foreach ($stmts as $stmt) {
            [$copy] = new NodeTraverser(new CloningVisitor())->traverse([$stmt]);
            $copies[] = $copy;
        }

        $result = new NodeTraverser($visitor)->traverse($copies);

        return $visitor->failed ? null : array_values(array_filter($result, static fn(\PhpParser\Node $n): bool => $n instanceof Node\Stmt));
    }

    /**
     * Есть ли $this в собственной области (включая нестатические замыкания и стрелочные функции).
     *
     * @param array<Node> $nodes
     */
    public static function usesThis(array $nodes): bool
    {
        $found = false;
        $walk  = static function (array $nodes) use (&$walk, &$found): void {
            foreach ($nodes as $node) {
                if (!$node instanceof Node || $found) {
                    continue;
                }

                if ($node instanceof Node\Expr\Variable && $node->name === 'this') {
                    $found = true;
                    return;
                }

                if (
                    $node instanceof Node\Stmt\ClassLike
                    || $node instanceof Node\Stmt\Function_
                    || ($node instanceof Node\Expr\Closure && $node->static)
                    || ($node instanceof Node\Expr\ArrowFunction && $node->static)
                ) {
                    continue;
                }

                foreach ($node->getSubNodeNames() as $sub) {
                    $val = $node->$sub;
                    $walk(is_array($val) ? $val : [$val]);
                }
            }
        };
        $walk($nodes);

        return $found;
    }

    /**
     * Обходит узлы собственной области видимости: не заходит в функции и классы,
     * но учитывает имена из use замыканий (они читаются в момент создания).
     *
     * @param array<Node|null>          $nodes
     * @param callable(Node): void       $visit
     */
    public static function walkOwnScope(array $nodes, callable $visit): void
    {
        foreach ($nodes as $node) {
            if (!$node instanceof Node) {
                continue;
            }

            $visit($node);

            if ($node instanceof Node\Expr\Closure) {
                foreach ($node->uses as $use) {
                    $visit($use->var);
                }

                continue;
            }

            if ($node instanceof Node\Expr\ArrowFunction) {
                // Свободные переменные стрелочной функции — переменные внешней области
                $params = [];
                foreach ($node->params as $param) {
                    if ($param->var instanceof Node\Expr\Variable && is_string($param->var->name)) {
                        $params[$param->var->name] = true;
                    }
                }

                self::walkOwnScope([$node->expr], static function (Node $inner) use ($visit, $params): void {
                    if (!$inner instanceof Node\Expr\Variable || !is_string($inner->name) || !isset($params[$inner->name])) {
                        $visit($inner);
                    }
                });
                continue;
            }

            if ($node instanceof Node\FunctionLike || $node instanceof Node\Stmt\ClassLike) {
                continue;
            }

            foreach ($node->getSubNodeNames() as $sub) {
                $val = $node->$sub;
                self::walkOwnScope(is_array($val) ? $val : [$val], $visit);
            }
        }
    }

    /**
     * Может ли аргумент функции передаваться по ссылке. Для встроенных функций
     * сигнатура известна; пользовательские и динамические — консервативно да.
     */
    public static function mayPassByRef(Node\Expr\FuncCall $call, int $index): bool
    {
        if (!$call->name instanceof Node\Name) {
            return true;
        }

        $name = $call->name->getLast();
        if (!function_exists($name)) {
            return true;
        }

        $function = new \ReflectionFunction($name);
        if (!$function->isInternal()) {
            return true;
        }

        $params = $function->getParameters();
        $param  = $params[$index] ?? ($function->isVariadic() ? end($params) : null);

        return $param instanceof \ReflectionParameter && $param->isPassedByReference();
    }

    /**
     * Позиции параметров по ссылке у функций и методов программы: lowercase имя →
     * индексы ('*' — вариативный по ссылке). Методы сопоставляются по имени: класс
     * объекта при вызове обычно неизвестен.
     *
     * @param  array<Node> $ast
     * @return array<string, array<int|string, true>>
     */
    public static function byRefParams(array $ast): array
    {
        $result = [];
        foreach (new NodeFinder()->findInstanceOf($ast, Node\FunctionLike::class) as $function) {
            if (!$function instanceof Node\Stmt\ClassMethod && !$function instanceof Node\Stmt\Function_) {
                continue;
            }

            foreach ($function->getParams() as $i => $param) {
                if ($param->byRef) {
                    $result[strtolower($function->name->toString())][$param->variadic ? '*' : $i] = true;
                }
            }
        }

        return $result;
    }

    private static function hasWrites(Node\Expr $expr): bool
    {
        return self::writtenVars([new Node\Stmt\Expression($expr)]) !== [];
    }
}
