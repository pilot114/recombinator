<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * Встраивает маленькие private-методы в места вызова внутри своего класса:
 *
 *   private function isEmpty($v) { return $v === null || $v === ''; }
 *   ... if ($this->isEmpty($x)) ...
 *   →
 *   ... if ($x === null || $x === '') ...
 *
 * Private-метод не переопределяется: `$this->m()` / `self::m()` из кода
 * класса всегда вызывает именно его. Сам метод остаётся (его может вызывать callable
 * `[$this, 'm']`); неиспользуемый удалит RemoveUnusedMethodVisitor.
 *
 * Условия:
 * - тело — одна инструкция `return expr;`, в expr нет замыканий, вызова самого метода,
 *   магических констант (__FUNCTION__, __METHOD__, __LINE__), func_get_args(), yield;
 * - у параметров нет by-ref, variadic и скалярных типов (int, string, …: при вызове
 *   было бы приведение); возвращаемый тип не скалярный по той же причине;
 * - аргументы позиционные, без распаковки; пропущенный аргумент берётся из
 *   константного значения по умолчанию;
 * - аргументы без побочных эффектов (переменная, литерал, константа, `$this->prop`);
 *   иначе аргумент один, его параметр встречается в expr ровно один раз и в expr нет
 *   других вызовов — тогда порядок вычисления не меняется.
 */
#[VisitorMeta('Встраивание маленьких private-методов')]
class PrivateMethodInlinerVisitor extends BaseVisitor
{
    private const array SCALAR_TYPES = ['int', 'float', 'string', 'bool', 'true', 'false', 'null', 'void', 'never'];

    /** @var array<int, array<string, Node\Stmt\ClassMethod>> id класса → lowercase имя → метод */
    private array $candidates = [];

    /** @var list<Node\Stmt\ClassLike> */
    private array $classStack = [];

    /** @var array<string, list<Node\Stmt\ClassMethod>> lowercase имя → методы программы */
    private array $methodsByName = [];

    #[\Override]
    public function beforeTraverse(array $nodes): ?array
    {
        parent::beforeTraverse($nodes);
        $this->candidates    = [];
        $this->classStack    = [];
        $this->methodsByName = [];
        foreach ($this->findNode(Node\Stmt\ClassMethod::class) as $declared) {
            $this->methodsByName[$declared->name->toLowerString()][] = $declared;
        }

        foreach ($this->findNode(Node\Stmt\Class_::class) as $class) {
            foreach ($class->getMethods() as $method) {
                if ($method->isPrivate() && !$method->isAbstract() && $this->inlinable($method)) {
                    $this->candidates[spl_object_id($class)][$method->name->toLowerString()] = $method;
                }
            }
        }

        return null;
    }

    #[\Override]
    public function enterNode(Node $node): ?Node
    {
        if ($node instanceof Node\Stmt\ClassLike) {
            $this->classStack[] = $node;
        }

        return null;
    }

    #[\Override]
    public function leaveNode(Node $node): int|Node|array|null
    {
        if ($node instanceof Node\Stmt\ClassLike) {
            array_pop($this->classStack);

            return parent::leaveNode($node);
        }

        $method = $this->target($node);
        if (!$method instanceof Node\Stmt\ClassMethod || !$node instanceof Node\Expr\MethodCall && !$node instanceof Node\Expr\StaticCall) {
            return parent::leaveNode($node);
        }

        $caller = $node->getAttribute('parent');
        while ($caller instanceof Node && !$caller instanceof Node\FunctionLike) {
            $caller = $caller->getAttribute('parent');
        }

        $inlined = $this->inline($method, $node->args, $caller instanceof Node\FunctionLike ? $caller : null);
        if ($inlined instanceof Node\Expr) {
            // Клон тела несёт parent исходного метода — связываем заново с местом вставки
            new NodeTraverser(new \PhpParser\NodeVisitor\ParentConnectingVisitor())->traverse([$inlined]);
            $inlined->setAttribute('parent', $node->getAttribute('parent'));
        }

        return $inlined ?? parent::leaveNode($node);
    }

    /** Метод своего класса, который вызывает узел, если он кандидат. */
    private function target(Node $node): ?Node\Stmt\ClassMethod
    {
        $class = end($this->classStack);
        if (!$class instanceof Node\Stmt\Class_ || !isset($this->candidates[spl_object_id($class)])) {
            return null;
        }

        $name = match (true) {
            $node instanceof Node\Expr\MethodCall && $node->var instanceof Node\Expr\Variable && $node->var->name === 'this' => $node->name,
            $node instanceof Node\Expr\StaticCall && $node->class instanceof Node\Name
                && $node->class->toLowerString() === 'self' => $node->name,
            default => null,
        };
        if (!$name instanceof Node\Identifier) {
            return null;
        }

        $method = $this->candidates[spl_object_id($class)][$name->toLowerString()] ?? null;

        // $this->m() для статического метода допустим, self::m() для обычного — только из
        // метода экземпляра; не разбираемся — встраиваем только «родные» формы вызова
        if ($method === null || $method->isStatic() !== $node instanceof Node\Expr\StaticCall) {
            return null;
        }

        return $method;
    }

    /** @param array<Node\Arg|Node\VariadicPlaceholder> $args */
    private function inline(Node\Stmt\ClassMethod $method, array $args, ?Node\FunctionLike $caller = null): ?Node\Expr
    {
        $return = $method->stmts[0] ?? null;
        if (!$return instanceof Node\Stmt\Return_ || !$return->expr instanceof Node\Expr) {
            return null;
        }

        if (count($args) > count($method->params)) {
            return null;
        }

        /** @var array<string, Node\Expr> $values */
        $values = [];
        $impure = [];
        foreach ($method->params as $index => $param) {
            if (!$param->var instanceof Node\Expr\Variable || !is_string($param->var->name)) {
                return null;
            }

            $arg = $args[$index] ?? null;
            if ($arg === null) {
                if (!$param->default instanceof Node\Expr || !$this->isPure($param->default)) {
                    return null;
                }

                $values[$param->var->name] = $param->default;
                continue;
            }

            if (!$arg instanceof Node\Arg || $arg->unpack || $arg->byRef || $arg->name instanceof Node\Identifier) {
                return null;
            }

            // Скалярный параметр: без приведения, только если тип аргумента тот же
            $scalar = $this->scalarOf($param->type);
            if ($scalar !== null && !$this->hasType($arg->value, $scalar, $caller)) {
                return null;
            }

            $values[$param->var->name] = $arg->value;
            if (!$this->isPure($arg->value)) {
                $impure[] = $param->var->name;
            }
        }

        if (!$this->orderPreserved($method, $return->expr, $values, $impure, $caller)) {
            return null;
        }

        // Скалярный возвращаемый тип: выражение уже этого типа (иначе было бы приведение)
        $returns = $this->scalarOf($method->returnType);
        if ($returns !== null && !$this->hasType($return->expr, $returns, $method)) {
            return null;
        }

        $body = $this->deepClone($return->expr);
        assert($body instanceof Node\Expr);
        $result = new NodeTraverser(new class ($values, $this) extends NodeVisitorAbstract {
            /** @param array<string, Node\Expr> $values */
            public function __construct(private readonly array $values, private readonly PrivateMethodInlinerVisitor $owner)
            {
            }

            public function leaveNode(Node $node): ?Node
            {
                if ($node instanceof Node\Expr\Variable && is_string($node->name) && isset($this->values[$node->name])) {
                    return $this->owner->cloneNode($this->values[$node->name]);
                }

                return null;
            }
        })->traverse([$body])[0];

        return $result instanceof Node\Expr ? $result : null;
    }

    public function cloneNode(Node $node): Node
    {
        return $this->deepClone($node);
    }

    private function inlinable(Node\Stmt\ClassMethod $method): bool
    {
        $stmts = $method->stmts ?? [];
        if (count($stmts) !== 1 || !$stmts[0] instanceof Node\Stmt\Return_ || !$stmts[0]->expr instanceof Node\Expr || $method->byRef) {
            return false;
        }

        if ($this->scalarOf($method->returnType) === 'reject') {
            return false;
        }

        $names = [];
        foreach ($method->params as $param) {
            if ($param->byRef || $param->variadic || $this->scalarOf($param->type) === 'reject' || !$param->var instanceof Node\Expr\Variable || !is_string($param->var->name)) {
                return false;
            }

            $names[$param->var->name] = true;
        }

        $self = $method->name->toLowerString();
        foreach ($this->findNode(Node::class, [$stmts[0]->expr]) as $node) {
            $forbidden = match (true) {
                $node instanceof Node\Expr\Closure, $node instanceof Node\Expr\ArrowFunction, $node instanceof Node\Expr\Yield_,
                $node instanceof Node\Expr\YieldFrom, $node instanceof Node\Scalar\MagicConst, $node instanceof Node\Expr\Include_,
                $node instanceof Node\Expr\Eval_, $node instanceof Node\Expr\Assign, $node instanceof Node\Expr\AssignOp,
                $node instanceof Node\Expr\AssignRef => true,
                $node instanceof Node\Expr\FuncCall => !$node->name instanceof Node\Name
                    || in_array($node->name->toLowerString(), ['func_get_args', 'func_get_arg', 'func_num_args', 'compact', 'extract', 'get_defined_vars'], true),
                ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\StaticCall) => !$node->name instanceof Node\Identifier
                    || $node->name->toLowerString() === $self,
                // Локальная переменная, не параметр (и не $this): значение неизвестно
                $node instanceof Node\Expr\Variable => !is_string($node->name) || $node->name !== 'this' && !isset($names[$node->name]),
                default => false,
            };
            if ($forbidden) {
                return false;
            }
        }

        return true;
    }

    /**
     * Скалярный тип, при котором возможно приведение: 'int', '?string', …; null — приведения
     * нет (класс, array, mixed, без типа); 'reject' — float (int → float) и объединения скаляров.
     */
    private function scalarOf(?Node $type): ?string
    {
        if ($type instanceof Node\NullableType) {
            $inner = $this->scalarOf($type->type);

            return $inner === null || $inner === 'reject' ? $inner : '?' . $inner;
        }

        if ($type instanceof Node\Identifier) {
            $name = $type->toLowerString();

            return match (true) {
                in_array($name, ['int', 'string', 'bool'], true) => $name,
                in_array($name, self::SCALAR_TYPES, true) => 'reject',
                default => null,
            };
        }

        if ($type instanceof Node\UnionType) {
            return array_any($type->types, fn(Node $t): bool => $this->scalarOf($t) !== null && (!$t instanceof Node\Identifier || $t->toLowerString() !== 'null'))
                ? 'reject'
                : null;
        }

        return null;
    }

    /** Заведомо ли выражение имеет скалярный тип ($type: 'int', '?string', …) — без приведения. */
    private function hasType(Node\Expr $expr, string $type, ?Node\FunctionLike $context): bool
    {
        $base = ltrim($type, '?');
        if ($base !== $type && $expr instanceof Node\Expr\ConstFetch && $expr->name->toLowerString() === 'null') {
            return true;
        }

        $actual = match (true) {
            $expr instanceof Node\Scalar\String_, $expr instanceof Node\Scalar\InterpolatedString, $expr instanceof Node\Expr\BinaryOp\Concat,
            $expr instanceof Node\Expr\Cast\String_ => 'string',
            $expr instanceof Node\Scalar\Int_, $expr instanceof Node\Expr\Cast\Int_ => 'int',
            $expr instanceof Node\Expr\ConstFetch && in_array($expr->name->toLowerString(), ['true', 'false'], true),
            $expr instanceof Node\Expr\BooleanNot, $expr instanceof Node\Expr\Cast\Bool_, $expr instanceof Node\Expr\Isset_,
            $expr instanceof Node\Expr\Empty_, $expr instanceof Node\Expr\Instanceof_,
            $expr instanceof Node\Expr\BinaryOp\BooleanAnd, $expr instanceof Node\Expr\BinaryOp\BooleanOr,
            $expr instanceof Node\Expr\BinaryOp\LogicalAnd, $expr instanceof Node\Expr\BinaryOp\LogicalOr, $expr instanceof Node\Expr\BinaryOp\LogicalXor,
            $expr instanceof Node\Expr\BinaryOp\Equal, $expr instanceof Node\Expr\BinaryOp\NotEqual, $expr instanceof Node\Expr\BinaryOp\Identical,
            $expr instanceof Node\Expr\BinaryOp\NotIdentical, $expr instanceof Node\Expr\BinaryOp\Smaller, $expr instanceof Node\Expr\BinaryOp\SmallerOrEqual,
            $expr instanceof Node\Expr\BinaryOp\Greater, $expr instanceof Node\Expr\BinaryOp\GreaterOrEqual => 'bool',
            $expr instanceof Node\Expr\Variable && is_string($expr->name) => $this->parameterType($expr->name, $context),
            $expr instanceof Node\Expr\Ternary => $expr->if instanceof Node\Expr && $this->hasType($expr->if, $type, $context) && $this->hasType($expr->else, $type, $context)
                ? $type
                : null,
            $expr instanceof Node\Expr\MethodCall || $expr instanceof Node\Expr\StaticCall => $this->ownResultType($expr),
            $expr instanceof Node\Expr\FuncCall => $this->internalResultType($expr),
            default => null,
        };

        // Параметр типа ?T не годится для T: null привёл бы к TypeError
        return $actual === $base || $actual === $type;
    }

    /**
     * Тип результата вызова метода своей иерархии (`$this->m()`, `self::m()`, `parent::m()`):
     * объявленный скалярный тип, одинаковый у всех одноимённых методов программы.
     */
    private function ownResultType(Node\Expr\MethodCall|Node\Expr\StaticCall $call): ?string
    {
        $own = $call instanceof Node\Expr\MethodCall
            ? $call->var instanceof Node\Expr\Variable && $call->var->name === 'this'
            : $call->class instanceof Node\Name && in_array($call->class->toLowerString(), ['self', 'static', 'parent'], true);
        if (!$own || !$call->name instanceof Node\Identifier) {
            return null;
        }

        $types = array_unique(array_map(
            fn(Node\Stmt\ClassMethod $m): string => $this->scalarOf($m->returnType) ?? 'none',
            $this->methodsByName[$call->name->toLowerString()] ?? [],
        ));

        return count($types) === 1 && !in_array($types[0] ?? 'none', ['none', 'reject'], true) ? $types[0] : null;
    }

    /** Тип результата встроенной функции по её объявлению (string, int, bool, ?…). */
    private function internalResultType(Node\Expr\FuncCall $call): ?string
    {
        if (!$call->name instanceof Node\Name || !function_exists($call->name->toString())) {
            return null;
        }

        $function = new \ReflectionFunction($call->name->toString());
        if (!$function->isInternal()) {
            return null;
        }

        $type = $function->getReturnType() ?? $function->getTentativeReturnType();
        if (!$type instanceof \ReflectionNamedType || !in_array($type->getName(), ['int', 'string', 'bool'], true)) {
            return null;
        }

        return ($type->allowsNull() ? '?' : '') . $type->getName();
    }

    /** Объявленный скалярный тип параметра, если переменная не переприсваивается. */
    private function parameterType(string $name, ?Node\FunctionLike $function): ?string
    {
        if (!$function instanceof Node\FunctionLike) {
            return null;
        }

        foreach ($function->getParams() as $param) {
            if (!$param->var instanceof Node\Expr\Variable || $param->var->name !== $name || $param->byRef || $param->variadic) {
                continue;
            }

            $type = $this->scalarOf($param->type);
            if ($type === null || $type === 'reject' || $this->mayChange($function, $name)) {
                return null;
            }

            return $type;
        }

        return null;
    }

    /** Может ли переменная функции измениться: присваивание, ссылка, foreach, list, передача в вызов. */
    private function mayChange(Node\FunctionLike $function, string $name): bool
    {
        foreach ($this->findNode(Node::class, $function->getStmts() ?? []) as $node) {
            $target = match (true) {
                $node instanceof Node\Expr\Assign, $node instanceof Node\Expr\AssignOp, $node instanceof Node\Expr\AssignRef,
                $node instanceof Node\Expr\PreInc, $node instanceof Node\Expr\PreDec, $node instanceof Node\Expr\PostInc,
                $node instanceof Node\Expr\PostDec => $node->var,
                $node instanceof Node\Stmt\Foreach_ => $node->valueVar,
                $node instanceof Node\Expr\ClosureUse => $node->byRef ? $node->var : null,
                // Аргумент вызова может быть по ссылке; встроенные функции проверяем рефлексией
                $node instanceof Node\Arg => $this->mayBeByRef($node) ? $node->value : null,
                default => null,
            };
            if (
                $target instanceof Node && array_any(
                    $this->findNode(Node\Expr\Variable::class, [$target]),
                    static fn(Node\Expr\Variable $v): bool => $v->name === $name,
                )
            ) {
                return true;
            }
        }

        return false;
    }

    private function mayBeByRef(Node\Arg $arg): bool
    {
        $call     = $arg->getAttribute('parent');
        $position = $call instanceof Node\Expr\CallLike ? array_search($arg, $call->getRawArgs(), true) : false;
        if (!is_int($position) || $arg->name instanceof Node\Identifier || $arg->unpack) {
            return true;
        }

        // Метод программы: по ссылке, если так объявлен хоть один одноимённый метод
        if (($call instanceof Node\Expr\MethodCall || $call instanceof Node\Expr\StaticCall) && $call->name instanceof Node\Identifier) {
            $methods = $this->methodsByName[$call->name->toLowerString()] ?? [];

            return $methods === [] || array_any($methods, static function (Node\Stmt\ClassMethod $m) use ($position): bool {
                $param = $m->params[$position] ?? end($m->params);

                return $param instanceof Node\Param && $param->byRef;
            });
        }

        if (!$call instanceof Node\Expr\FuncCall || !$call->name instanceof Node\Name || !function_exists($call->name->toString())) {
            return true;
        }

        $parameters = new \ReflectionFunction($call->name->toString())->getParameters();
        $parameter  = $parameters[$position] ?? end($parameters);

        return $parameter === false || $parameter->isPassedByReference() || $parameter->canBePassedByValue() === false;
    }

    private function isPure(Node\Expr $expr): bool
    {
        return match (true) {
            $expr instanceof Node\Scalar, $expr instanceof Node\Expr\ConstFetch, $expr instanceof Node\Expr\ClassConstFetch => true,
            $expr instanceof Node\Expr\Variable => is_string($expr->name),
            $expr instanceof Node\Expr\PropertyFetch => $expr->var instanceof Node\Expr\Variable && $expr->var->name === 'this'
                && $expr->name instanceof Node\Identifier,
            $expr instanceof Node\Expr\Array_ => array_all(
                $expr->items,
                fn(Node\ArrayItem $i): bool => !$i->byRef && !$i->unpack && $this->isPure($i->value) && (!$i->key instanceof Node\Expr || $this->isPure($i->key)),
            ),
            $expr instanceof Node\Expr\UnaryMinus => $this->isPure($expr->expr),
            default => false,
        };
    }

    /**
     * Не меняет ли подстановка порядок и число вычислений аргументов.
     *
     * @param array<string, Node\Expr> $values
     * @param list<string>             $impure параметры с аргументами-вызовами
     */
    private function orderPreserved(Node\Stmt\ClassMethod $method, Node\Expr $expr, array $values, array $impure, ?Node\FunctionLike $caller = null): bool
    {
        // Локальная переменная вызывающего без ссылок на неё: вызовы внутри выражения её не изменят
        $mutable = array_keys(array_filter(
            $values,
            fn(Node\Expr $v): bool => !$this->isImmutable($v)
                && !($v instanceof Node\Expr\Variable && is_string($v->name) && $this->unaliased($v->name, $caller)),
        ));
        if ($mutable === []) {
            return true;
        }

        $uses = $this->variableUses($expr);
        if (!$this->hasCalls($expr)) {
            // Остальное выражение без эффектов: переменные читаются в то же состояние;
            // аргумент-вызов один и вычисляется ровно один раз
            return $impure === [] || count($impure) === 1 && ($uses[$impure[0]] ?? 0) === 1;
        }

        // Делегирование: return $this->other($a, $b) — параметры ровно по разу, по порядку,
        // прямыми аргументами вызова верхнего уровня; тогда вычисление то же
        if (!$expr instanceof Node\Expr\MethodCall && !$expr instanceof Node\Expr\StaticCall && !$expr instanceof Node\Expr\FuncCall && !$expr instanceof Node\Expr\New_) {
            return false;
        }

        if ($expr instanceof Node\Expr\MethodCall && (!$expr->var instanceof Node\Expr\Variable || $expr->var->name !== 'this')) {
            return false;
        }

        $order = [];
        foreach ($expr->getRawArgs() as $arg) {
            if (!$arg instanceof Node\Arg || $arg->unpack) {
                return false;
            }

            if ($arg->value instanceof Node\Expr\Variable && is_string($arg->value->name) && isset($values[$arg->value->name])) {
                $order[] = $arg->value->name;
            } elseif (!$this->isImmutable($arg->value)) {
                return false;
            }
        }

        $params = array_values(array_filter(
            array_map(static fn(Node\Param $p): string => $p->var instanceof Node\Expr\Variable && is_string($p->var->name) ? $p->var->name : '', $method->params),
            static fn(string $name): bool => in_array($name, $mutable, true),
        ));
        $used   = array_values(array_filter($order, static fn(string $name): bool => in_array($name, $mutable, true)));

        return $used === $params && array_all($mutable, static fn(string $name): bool => ($uses[$name] ?? 0) === 1);
    }

    /** Переменная функции, на которую нет ссылок: изменить её может только сама функция. */
    private function unaliased(string $name, ?Node\FunctionLike $function): bool
    {
        if (!$function instanceof Node\FunctionLike || $name === 'this' || $name === 'GLOBALS') {
            return false;
        }

        if (array_any($function->getParams(), static fn(Node\Param $p): bool => $p->byRef && $p->var instanceof Node\Expr\Variable && $p->var->name === $name)) {
            return false;
        }

        if ($function instanceof Node\Expr\Closure && array_any($function->uses, static fn(Node\ClosureUse $u): bool => $u->byRef && $u->var->name === $name)) {
            return false;
        }

        foreach ($this->findNode(Node::class, $function->getStmts() ?? []) as $node) {
            $aliased = match (true) {
                $node instanceof Node\Expr\AssignRef => true,
                $node instanceof Node\Expr\ClosureUse => $node->byRef,
                $node instanceof Node\Stmt\Global_, $node instanceof Node\Stmt\Static_ => true,
                $node instanceof Node\Stmt\Foreach_ => $node->byRef,
                $node instanceof Node\Expr\Variable => !is_string($node->name),
                $node instanceof Node\Expr\FuncCall => $node->name instanceof Node\Name
                    && in_array($node->name->toLowerString(), ['extract', 'parse_str', 'get_defined_vars'], true),
                $node instanceof Node\Arg => $node->value instanceof Node\Expr\Variable && $node->value->name === $name && $this->mayBeByRef($node),
                default => false,
            };
            if ($aliased) {
                return false;
            }
        }

        return true;
    }

    private function isImmutable(Node\Expr $expr): bool
    {
        return match (true) {
            $expr instanceof Node\Scalar, $expr instanceof Node\Expr\ConstFetch, $expr instanceof Node\Expr\ClassConstFetch => true,
            $expr instanceof Node\Expr\UnaryMinus => $this->isImmutable($expr->expr),
            $expr instanceof Node\Expr\Array_ => array_all(
                $expr->items,
                fn(Node\ArrayItem $i): bool => !$i->byRef && !$i->unpack && $this->isImmutable($i->value) && (!$i->key instanceof Node\Expr || $this->isImmutable($i->key)),
            ),
            default => false,
        };
    }

    /** @return array<string, int> сколько раз встречается каждая переменная */
    private function variableUses(Node\Expr $expr): array
    {
        $uses = [];
        foreach ($this->findNode(Node\Expr\Variable::class, [$expr]) as $var) {
            if (is_string($var->name)) {
                $uses[$var->name] = ($uses[$var->name] ?? 0) + 1;
            }
        }

        return $uses;
    }

    private function hasCalls(Node\Expr $expr): bool
    {
        return $this->findNode(Node\Expr\CallLike::class, [$expr]) !== []
            || $this->findNode(Node\Expr\PropertyFetch::class, [$expr]) !== [];
    }
}
