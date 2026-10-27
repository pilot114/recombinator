<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;

/**
 * Специализирует функцию под аргумент, одинаковый во всех местах вызова:
 *
 *   function getConnection(array $params) { ... createDriver($params['driver'] ?? null) ... }
 *   getConnection(['driver' => 'pdo_sqlite', 'path' => ...]);   // единственный вызов
 *   →
 *   function getConnection(array $params) { ... createDriver('pdo_sqlite' ?? null) ... }
 *
 * Дальше ConstFoldVisitor и DeadBranchVisitor сворачивают выражения и ветки, а
 * следующий вызов (createDriver('pdo_sqlite', null)) специализируется тем же правилом.
 *
 * Подставляется:
 * - скалярный литерал (строка, число, true/false/null) — вместо каждого чтения параметра;
 * - литеральный массив — только вместо `$p['ключ']` с литеральным ключом (весь массив
 *   не копируется); отсутствующий ключ в `$p['k'] ?? …` и `isset($p['k'])` — null.
 *
 * Условия: функция объявлена в программе один раз, её имя не встречается строкой
 * (callable) и все вызовы — прямые, позиционные, без распаковки; параметр не
 * изменяется в теле (присваивание, ссылка, foreach, list, unset, передача по ссылке,
 * `$$`, extract, compact, func_get_args) и не захвачен замыканием по ссылке; значение
 * подходит к объявленному типу без приведения.
 */
#[VisitorMeta('Специализация функции под одинаковый константный аргумент')]
class ConstantArgumentVisitor extends BaseVisitor
{
    /** @var \SplObjectStorage<Node\Stmt\Function_, array<string, Node\Expr>> функция → параметр → значение */
    private \SplObjectStorage $plans;

    /** @var \SplObjectStorage<Node\Expr\Variable, true> чтения, где подстановка допустима при частичном плане */
    private \SplObjectStorage $allowed;

    /** @var \SplObjectStorage<Node\Stmt\Function_, array<string, true>> параметры с частичным планом */
    private \SplObjectStorage $partial;

    /** @var array<string, list<Node\Stmt\Function_>> функции программы по lowercase имени */
    private array $functions = [];

    public function __construct()
    {
        $this->plans   = new \SplObjectStorage();
        $this->allowed = new \SplObjectStorage();
        $this->partial = new \SplObjectStorage();
    }

    #[\Override]
    public function beforeTraverse(array $nodes): ?array
    {
        parent::beforeTraverse($nodes);
        $this->plans   = new \SplObjectStorage();
        $this->allowed = new \SplObjectStorage();
        $this->partial = new \SplObjectStorage();

        /** @var array<string, list<Node\Stmt\Function_>> $functions */
        $functions = [];
        $calls     = [];
        $strings   = [];
        foreach ($this->findNode(Node::class) as $node) {
            if ($node instanceof Node\Stmt\Function_) {
                $functions[$node->name->toLowerString()][] = $node;
            } elseif ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name) {
                $calls[strtolower(ltrim($node->name->toString(), '\\'))][] = $node;
            } elseif ($node instanceof Node\Scalar\String_) {
                $strings[strtolower(ltrim($node->value, '\\'))] = true;
            }
        }

        $this->functions = $functions;
        foreach ($functions as $name => $declarations) {
            if (count($declarations) !== 1 || isset($strings[$name]) || !isset($calls[$name])) {
                continue;
            }

            $plan = $this->plan($declarations[0], $calls[$name]);
            if ($plan !== []) {
                $this->plans[$declarations[0]] = $plan;
            }
        }

        return null;
    }

    public function enterNode(Node $node): ?Node
    {
        if (!$node instanceof Node\Stmt\Function_ || !isset($this->plans[$node])) {
            return null;
        }

        $plan  = $this->plans[$node];
        $stmts = new NodeTraverser(new class ($plan, $this, $node) extends NodeVisitorAbstract {
            /** @param array<string, Node\Expr> $plan */
            public function __construct(
                private readonly array $plan,
                private readonly ConstantArgumentVisitor $owner,
                private readonly Node\Stmt\Function_ $function,
            ) {
            }

            public function enterNode(Node $node): ?int
            {
                // Вложенные функции и замыкания — своя область видимости
                return $node instanceof Node\FunctionLike ? NodeVisitor::DONT_TRAVERSE_CHILDREN : null;
            }

            public function leaveNode(Node $node): ?Node
            {
                return $this->owner->substitute($node, $this->plan, $this->function);
            }
        })->traverse($node->stmts);

        $node->stmts = array_values(array_filter($stmts, static fn(Node $stmt): bool => $stmt instanceof Node\Stmt));

        return $node;
    }

    /**
     * Замена одного узла по плану (вызывается из обхода тела).
     *
     * @param array<string, Node\Expr> $plan
     */
    public function substitute(Node $node, array $plan, ?Node\Stmt\Function_ $function = null): ?Node
    {
        // Частичная подстановка: только в чтения до первой записи
        $variable = $node instanceof Node\Expr\ArrayDimFetch ? $node->var : ($node instanceof Node\Expr\Isset_ ? ($node->vars[0] ?? null) : $node);
        while ($variable instanceof Node\Expr\ArrayDimFetch) {
            $variable = $variable->var;
        }

        if (
            $variable instanceof Node\Expr\Variable && is_string($variable->name) && $function instanceof \PhpParser\Node\Stmt\Function_
            && isset($this->partial[$function]) && isset($this->partial[$function][$variable->name]) && !isset($this->allowed[$variable])
        ) {
            return null;
        }

        // isset($p['k']) по литеральному массиву: есть ли ключ со значением не null
        if ($node instanceof Node\Expr\Isset_ && count($node->vars) === 1) {
            $value = $this->element($node->vars[0], $plan);

            return $value === false ? null : new Node\Expr\ConstFetch(new Node\Name(
                $value instanceof \PhpParser\Node\Expr && (!$value instanceof Node\Expr\ConstFetch || $value->name->toLowerString() !== 'null') ? 'true' : 'false',
            ));
        }

        if ($node->getAttribute('parent') instanceof Node\Expr\Isset_) {
            return null; // isset() требует переменную
        }

        if ($node instanceof Node\Expr\Variable && is_string($node->name) && isset($plan[$node->name]) && !$plan[$node->name] instanceof Node\Expr\Array_) {
            return $this->deepClone($plan[$node->name]);
        }

        if (
            !$node instanceof Node\Expr\ArrayDimFetch || !$node->var instanceof Node\Expr\Variable || !is_string($node->var->name)
            || !($plan[$node->var->name] ?? null) instanceof Node\Expr\Array_ || !$node->dim instanceof Node\Scalar
        ) {
            return null;
        }

        $value = $this->element($node, $plan);
        if ($value instanceof Node\Expr) {
            return $this->deepClone($value);
        }

        // Отсутствующий ключ: null только там, где чтение не выдаёт предупреждение
        $parent = $node->getAttribute('parent');
        $quiet  = $parent instanceof Node\Expr\BinaryOp\Coalesce && $parent->left === $node;

        return $quiet && $value === null ? new Node\Expr\ConstFetch(new Node\Name('null')) : null;
    }

    /**
     * Элемент литерального массива-параметра по литеральному ключу: значение, null —
     * ключа нет, false — выражение не такое.
     *
     * @param array<string, Node\Expr> $plan
     */
    private function element(Node $node, array $plan): Node\Expr|false|null
    {
        if (
            !$node instanceof Node\Expr\ArrayDimFetch || !$node->var instanceof Node\Expr\Variable || !is_string($node->var->name)
            || !($plan[$node->var->name] ?? null) instanceof Node\Expr\Array_
            || !$node->dim instanceof Node\Scalar\String_ && !$node->dim instanceof Node\Scalar\Int_
        ) {
            return false;
        }

        $array = $plan[$node->var->name];
        foreach ($array->items as $item) {
            if (($item->key instanceof Node\Scalar\String_ || $item->key instanceof Node\Scalar\Int_) && $item->key->value === $node->dim->value) {
                return $item->value;
            }
        }

        // Ключ без явного имени (список) — по позиции не ищем
        return array_any($array->items, static fn(Node\ArrayItem $i): bool => !$i->key instanceof \PhpParser\Node\Expr) ? false : null;
    }

    /**
     * @param  list<Node\Expr\FuncCall>  $calls
     * @return array<string, Node\Expr>
     */
    private function plan(Node\Stmt\Function_ $function, array $calls): array
    {
        if ($this->usesArgumentList($function)) {
            return [];
        }

        $plan = [];
        foreach ($function->params as $index => $param) {
            if (!$param->var instanceof Node\Expr\Variable || !is_string($param->var->name) || $param->byRef || $param->variadic) {
                continue;
            }

            $value = null;
            foreach ($calls as $call) {
                $args = $call->getRawArgs();
                if (array_any($args, static fn(Node $a): bool => !$a instanceof Node\Arg || $a->unpack || $a->byRef || $a->name instanceof Node\Identifier)) {
                    return [];
                }

                $arg   = $args[$index] ?? null;
                $given = $arg instanceof Node\Arg ? $arg->value : $param->default;
                if (!$given instanceof Node\Expr || !$this->isLiteral($given) || $value instanceof \PhpParser\Node\Expr && !$this->same($value, $given)) {
                    continue 2;
                }

                $value ??= $given;
            }

            $name = $param->var->name;
            if (!$value instanceof Node\Expr || !$this->fitsType($value, $param->type)) {
                continue;
            }

            if (!$this->changes($function->stmts, $name, $function)) {
                $plan[$name] = $value;
                continue;
            }

            // Параметр изменяется: значение известно только в чтениях до первой записи
            $reads = $this->readsBeforeWrite($function, $name);
            if ($reads !== []) {
                $plan[$name] = $value;
                foreach ($reads as $read) {
                    $this->allowed[$read] = true;
                }

                $this->partial[$function] = [...$this->partial[$function] ?? [], $name => true];
            }
        }

        return $plan;
    }

    private function isLiteral(Node\Expr $expr): bool
    {
        return match (true) {
            $expr instanceof Node\Scalar\String_, $expr instanceof Node\Scalar\Int_, $expr instanceof Node\Scalar\Float_ => true,
            $expr instanceof Node\Expr\ConstFetch => in_array($expr->name->toLowerString(), ['true', 'false', 'null'], true),
            $expr instanceof Node\Expr\UnaryMinus => $expr->expr instanceof Node\Scalar\Int_ || $expr->expr instanceof Node\Scalar\Float_,
            // Массив: литеральные ключи; значения — литералы или константы (вычисляются при каждом чтении так же)
            $expr instanceof Node\Expr\Array_ => array_all($expr->items, fn(Node\ArrayItem $i): bool => !$i->byRef && !$i->unpack
                && (!$i->key instanceof \PhpParser\Node\Expr || $i->key instanceof Node\Scalar\String_ || $i->key instanceof Node\Scalar\Int_)
                && ($this->isLiteral($i->value) || $this->isConstantExpr($i->value))),
            default => false,
        };
    }

    /** Константа или конкатенация констант и литералов (`APP_ROOT . '/var'`). */
    private function isConstantExpr(Node\Expr $expr): bool
    {
        return match (true) {
            $expr instanceof Node\Expr\ConstFetch, $expr instanceof Node\Expr\ClassConstFetch => true,
            $expr instanceof Node\Expr\BinaryOp\Concat => ($this->isLiteral($expr->left) || $this->isConstantExpr($expr->left))
                && ($this->isLiteral($expr->right) || $this->isConstantExpr($expr->right)),
            default => false,
        };
    }

    private function same(Node\Expr $a, Node\Expr $b): bool
    {
        $printer = new \PhpParser\PrettyPrinter\Standard();

        return $printer->prettyPrintExpr($a) === $printer->prettyPrintExpr($b);
    }

    /** Значение подходит к объявленному типу без приведения. */
    private function fitsType(Node\Expr $value, ?Node $type): bool
    {
        if (!$type instanceof \PhpParser\Node) {
            return true;
        }

        $actual = match (true) {
            $value instanceof Node\Scalar\String_ => 'string',
            $value instanceof Node\Scalar\Int_, $value instanceof Node\Expr\UnaryMinus && $value->expr instanceof Node\Scalar\Int_ => 'int',
            $value instanceof Node\Scalar\Float_, $value instanceof Node\Expr\UnaryMinus => 'float',
            $value instanceof Node\Expr\Array_ => 'array',
            $value instanceof Node\Expr\ConstFetch => $value->name->toLowerString() === 'null' ? 'null' : 'bool',
            default => null,
        };

        $allowed = match (true) {
            $type instanceof Node\NullableType => ['null', ...$this->typeNames($type->type)],
            $type instanceof Node\UnionType => array_merge(...array_map($this->typeNames(...), $type->types)),
            default => $this->typeNames($type),
        };

        return $actual !== null && (in_array($actual, $allowed, true) || in_array('mixed', $allowed, true)
            || $actual === 'bool' && (in_array('true', $allowed, true) || in_array('false', $allowed, true)));
    }

    /** @return list<string> */
    private function typeNames(Node $type): array
    {
        return $type instanceof Node\Identifier ? [$type->toLowerString()] : [];
    }

    private function usesArgumentList(Node\Stmt\Function_ $function): bool
    {
        foreach ($this->findNode(Node::class, $function->stmts) as $node) {
            if (
                $node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name
                && in_array($node->name->toLowerString(), ['func_get_args', 'func_get_arg', 'func_num_args', 'compact', 'extract', 'get_defined_vars', 'parse_str'], true)
            ) {
                return true;
            }

            if ($node instanceof Node\Expr\Variable && !is_string($node->name)) {
                return true; // $$name
            }
        }

        return false;
    }

    /** Изменяется ли переменная в теле функции (вне вложенных функций — кроме захвата по ссылке). */
    /**
     * Чтения переменной, выполняемые до любой её записи: инструкции верхнего уровня до
     * первой изменяющей и условие `if`, в ветках которого запись.
     *
     * @return list<Node\Expr\Variable>
     */
    private function readsBeforeWrite(Node\Stmt\Function_ $function, string $name): array
    {
        $scope = [];
        foreach ($function->stmts as $stmt) {
            if (!$this->changes([$stmt], $name, $function)) {
                $scope[] = $stmt;
                continue;
            }

            if ($stmt instanceof Node\Stmt\If_ && !$this->changes([new Node\Stmt\Expression($stmt->cond)], $name, $function)) {
                $scope[] = $stmt->cond;
            }

            break;
        }

        return array_values(array_filter(
            $this->findNode(Node\Expr\Variable::class, $scope),
            static fn(Node\Expr\Variable $v): bool => $v->name === $name,
        ));
    }

    /** @param array<Node> $stmts */
    private function changes(array $stmts, string $name, ?Node\Stmt\Function_ $function = null): bool
    {
        foreach ($this->findNode(Node::class, $stmts) as $node) {
            $targets = match (true) {
                $node instanceof Node\Expr\AssignRef => [$node->var, $node->expr],
                $node instanceof Node\Expr\Assign, $node instanceof Node\Expr\AssignOp,
                $node instanceof Node\Expr\PreInc, $node instanceof Node\Expr\PreDec, $node instanceof Node\Expr\PostInc,
                $node instanceof Node\Expr\PostDec => [$node->var],
                $node instanceof Node\Stmt\Foreach_ => [$node->keyVar, $node->valueVar, $node->byRef ? $node->expr : null],
                $node instanceof Node\Stmt\Unset_ => $node->vars,
                $node instanceof Node\Stmt\Global_ => $node->vars,
                $node instanceof Node\Stmt\Static_ => array_map(static fn(Node\StaticVar $v): Node => $v->var, $node->vars),
                $node instanceof Node\Expr\ClosureUse => $node->byRef ? [$node->var] : [],
                $node instanceof Node\Arg => [$node->value], // может быть по ссылке: внутрь вызова не смотрим
                default => [],
            };

            foreach ($targets as $target) {
                $base = $target;
                while ($base instanceof Node\Expr\ArrayDimFetch || $base instanceof Node\Expr\PropertyFetch) {
                    $base = $base->var;
                }

                $direct = $base instanceof Node\Expr\Variable && $base->name === $name;
                // Передача в вызов: опасна только переменная целиком (по ссылке), не её элемент,
                // и только если после вызова её ещё читают
                if ($direct && ($node instanceof Node\Arg ? $target === $base && $this->mayBeByRef($node) && (!$function instanceof \PhpParser\Node\Stmt\Function_ || !$this->lastUse($function, $node, $name)) : true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Аргумент — единственное упоминание переменной в завершающем `return` функции. */
    private function lastUse(Node\Stmt\Function_ $function, Node\Arg $arg, string $name): bool
    {
        $last = end($function->stmts);
        if (!$last instanceof Node\Stmt\Return_ || !$last->expr instanceof Node\Expr) {
            return false;
        }

        $uses = array_filter($this->findNode(Node\Expr\Variable::class, [$last]), static fn(Node\Expr\Variable $v): bool => $v->name === $name);

        return count($uses) === 1 && reset($uses) === $arg->value;
    }

    private function mayBeByRef(Node\Arg $arg): bool
    {
        $call = $arg->getAttribute('parent');
        if (!$call instanceof Node\Expr\FuncCall || !$call->name instanceof Node\Name) {
            return true;
        }

        // Функция программы — по её объявлению
        $declared = $this->functions[strtolower(ltrim($call->name->toString(), '\\'))] ?? [];
        if (count($declared) === 1) {
            $position = array_search($arg, $call->args, true);
            $params   = $declared[0]->params;
            $param    = $params[is_int($position) ? $position : 0] ?? end($params);

            return $param === false || $param->byRef;
        }

        if (!function_exists($call->name->toString())) {
            return true;
        }

        $position   = array_search($arg, $call->args, true);
        $parameters = new \ReflectionFunction($call->name->toString())->getParameters();
        $parameter  = $parameters[is_int($position) ? $position : 0] ?? end($parameters);

        return $parameter === false || $parameter->isPassedByReference();
    }
}
