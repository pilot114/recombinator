<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\NodeFinder;
use Recombinator\Transformation\BodyInliner;
use Recombinator\Transformation\State\ObjectState;
use Recombinator\Transformation\State\PropValue;
use Recombinator\Transformation\State\RunContext;
use Recombinator\Transformation\State\RunResult;

/**
 * Отслеживает состояние свойств живого объекта (в том числе содержимое массивов)
 * по прямолинейному коду верхнего уровня и специализирует вызовы его методов
 * с известными аргументами:
 *
 *   $cnt = new Container(['settings' => [...]]);   // container = ['settings' => [...]]
 *   $cnt->set('app', static function ($c) {...});  // container['app'] = function ...
 *   $s   = $cnt->get('settings');                  // has(), resolved(), array_key_exists,
 *                                                  // instanceof \Closure — вычисляются
 *   →
 *   $s   = [...];
 *   $app = $cnt->get('app');
 *   →
 *   $get_entry_1 = \call_user_func(static function ($c) {...}, $cnt);
 *   $cnt->set('app', $get_entry_1);                // запись в private-свойство — через
 *   $app = $get_entry_1;                           // публичный сеттер класса
 *
 * Объект остаётся: класс не удаляется, в результате нет обращений к закрытым членам.
 *
 * Состояние теряется (кроме «стабильных» свойств), когда объект уходит в неизвестный
 * код: передаётся аргументом, захватывается замыканием, встречается в ветвлении/цикле.
 * Стабильное свойство пишется только в конструкторе и в методах, которые нигде
 * не вызываются — его значение известно всегда.
 */
#[VisitorMeta('Специализация вызовов объекта по отслеживаемому состоянию его свойств')]
class ObjectStateSpecializerVisitor extends BaseVisitor
{
    private const int MAX_DEPTH = 8;

    /** @var array<string, Node\Stmt\Class_> */
    private array $classes = [];

    /** @var array<string, array<string, true>> lowercase класс → стабильные свойства */
    private array $stableProps = [];

    /** @var array<string, true> имена переменных в коде */
    private array $usedVars = [];

    private int $counter = 0;

    /** @var array<string, int> сколько раз присваивается каждая переменная верхнего уровня */
    private array $assignCount = [];

    /** @var array<string, true> временные переменные, созданные этим правилом */
    private array $freshVars = [];

    #[\Override]
    public function beforeTraverse(array $nodes): ?array
    {
        parent::beforeTraverse($nodes);
        $this->classes     = [];
        $this->stableProps = [];
        foreach ($this->findNode(Node\Stmt\Class_::class) as $class) {
            if ($class->name instanceof Node\Identifier && !$class->isAbstract()) {
                $this->classes[strtolower($class->name->toString())] = $class;
            }
        }

        if ($this->classes === []) {
            return null;
        }

        $this->usedVars = [];
        foreach ($this->findNode(Node\Expr\Variable::class) as $var) {
            if (is_string($var->name)) {
                $this->usedVars[$var->name] = true;
            }
        }

        $assignCount       = ObjectMethodInlinerVisitor::topLevelAssignCounts($nodes);
        $this->assignCount = $assignCount;

        $this->freshVars   = [];

        /** @var array<string, ObjectState> $states */
        $states  = [];
        $changed = false;
        $result  = [];
        foreach ($nodes as $stmt) {
            $produced = $this->processStmt($stmt, $states, $assignCount);
            if ($produced === null) {
                $result[] = $stmt;
                continue;
            }

            array_push($result, ...$produced);
            $changed = true;
        }

        return $changed ? $result : null;
    }

    /**
     * @param  array<string, ObjectState> $states
     * @param  array<string, int>         $assignCount
     * @return array<Node\Stmt>|null  null — оператор не изменился
     */
    private function processStmt(Node $stmt, array &$states, array $assignCount): ?array
    {
        // $v = new C(...)
        if (
            $stmt instanceof Node\Stmt\Expression
            && $stmt->expr instanceof Node\Expr\Assign
            && $stmt->expr->var instanceof Node\Expr\Variable
            && is_string($stmt->expr->var->name)
            && $stmt->expr->expr instanceof Node\Expr\New_
        ) {
            $this->escapeAll($stmt->expr->expr, $states);
            $name = $stmt->expr->var->name;
            unset($states[$name]);
            if (($assignCount[$name] ?? 0) === 1) {
                $state = $this->construct($stmt->expr->expr);
                if ($state instanceof ObjectState) {
                    $states[$name] = $state;
                }
            }

            return null;
        }

        // $v->procedure(args); — объект остаётся вызовом, обновляем состояние
        if (
            $stmt instanceof Node\Stmt\Expression
            && $stmt->expr instanceof Node\Expr\MethodCall
            && $stmt->expr->var instanceof Node\Expr\Variable
            && is_string($stmt->expr->var->name)
            && isset($states[$stmt->expr->var->name])
        ) {
            $var   = $stmt->expr->var->name;
            $state = $states[$var];
            $this->escapeArgs($stmt->expr, $states);

            $run = $this->runCall($var, $state, $stmt->expr, 'simulate');
            $states[$var] = $run instanceof RunResult ? $run->state : $state->escaped();

            return null;
        }

        // $x = $v->m(args); $v->m(args)->run(); — вызов вычисляется в операторе первым,
        // поэтому его побочные эффекты можно вывести операторами перед ним
        $first = $this->firstEvaluatedCall($stmt, $states);
        if ($first !== null) {
            [$call, $replace] = $first;
            $var = $call->var instanceof Node\Expr\Variable && is_string($call->var->name) ? $call->var->name : '';
            $run = $this->runCall($var, $states[$var], $call, 'specialize');
            if ($run instanceof \Recombinator\Transformation\State\RunResult && $run->value instanceof Node\Expr) {
                $this->escapeArgs($call, $states);
                $states[$var] = $run->state;
                $replace($run->value);
                $this->escapeAll($stmt, $states);

                return [...$run->emitted, $stmt];
            }
        }

        // Вызовы внутри выражений: заменяем только чистые (без операторов и записи состояния)
        $changed = false;
        if (!$stmt instanceof Node\Stmt\ClassLike && !$stmt instanceof Node\Stmt\Function_) {
            $changed = $this->replacePureCalls($stmt, $states);
        }

        $this->escapeAll($stmt, $states);

        return $changed ? [$stmt] : null;
    }

    /**
     * Заменяет `$v->m(args)` в собственной области оператора на значение, если вызов
     * чистый при известном состоянии.
     *
     * @param array<string, ObjectState> $states
     */
    private function replacePureCalls(Node $stmt, array $states): bool
    {
        if ($states === []) {
            return false;
        }

        $changed = false;
        $visit   = function (Node $node) use (&$visit, &$changed, $states): void {
            foreach ($node->getSubNodeNames() as $sub) {
                $value = $node->$sub;
                foreach (is_array($value) ? $value : [$value] as $key => $child) {
                    // Вложенные операторы (тела if/циклов) могут выполняться после изменений
                    // объекта или многократно — заменяем только в выражениях самого оператора
                    if (!$child instanceof Node || $child instanceof Node\FunctionLike || $child instanceof Node\Stmt) {
                        continue;
                    }

                    $replacement = null;
                    if (
                        $child instanceof Node\Expr\MethodCall
                        && $child->var instanceof Node\Expr\Variable
                        && is_string($child->var->name)
                        && isset($states[$child->var->name])
                    ) {
                        $run = $this->runCall($child->var->name, $states[$child->var->name], $child, 'specialize');
                        if (
                            $run instanceof RunResult
                            && $run->emitted === []
                            && !$run->wrote
                            && $run->value instanceof Node\Expr
                        ) {
                            $replacement = $run->value;
                        }
                    }

                    if ($replacement instanceof \PhpParser\Node\Expr) {
                        if (is_array($value)) {
                            $value[$key]  = $replacement;
                            $node->$sub   = $value;
                        } else {
                            $node->$sub = $replacement;
                        }

                        $changed = true;
                        continue;
                    }

                    $visit($child);
                }
            }
        };
        $visit($stmt);

        return $changed;
    }

    /**
     * Вызов метода отслеживаемого объекта, который вычисляется в операторе раньше
     * всего остального: правая часть присваивания переменной, получатель цепочки,
     * первый аргумент вызова со статическим именем.
     *
     * @param  array<string, ObjectState> $states
     * @return array{Node\Expr\MethodCall, \Closure(Node\Expr): void}|null
     */
    private function firstEvaluatedCall(Node $stmt, array $states): ?array
    {
        if (!$stmt instanceof Node\Stmt\Expression && !$stmt instanceof Node\Stmt\Return_ && !$stmt instanceof Node\Stmt\Echo_) {
            return null;
        }

        $holder = $stmt;
        $field  = $stmt instanceof Node\Stmt\Echo_ ? 'exprs' : 'expr';
        $index  = $stmt instanceof Node\Stmt\Echo_ ? 0 : null;
        for ($depth = 0; $depth < 32; $depth++) {
            $expr = $index !== null ? ($holder->$field[$index] ?? null) : $holder->$field;
            if ($expr instanceof Node\Arg) {
                $holder = $expr;
                $field  = 'value';
                $index  = null;
                continue;
            }

            if (!$expr instanceof Node\Expr) {
                return null;
            }

            if (
                $expr instanceof Node\Expr\MethodCall
                && $expr->var instanceof Node\Expr\Variable
                && is_string($expr->var->name)
                && isset($states[$expr->var->name])
            ) {
                $replace = static function (Node\Expr $value) use ($holder, $field, $index): void {
                    if ($index !== null) {
                        $items         = $holder->$field;
                        $items[$index] = $value;
                        $holder->$field = $items;
                    } else {
                        $holder->$field = $value;
                    }
                };

                return [$expr, $replace];
            }

            [$holder, $field, $index] = match (true) {
                $expr instanceof Node\Expr\Assign && $expr->var instanceof Node\Expr\Variable => [$expr, 'expr', null],
                $expr instanceof Node\Expr\MethodCall,
                $expr instanceof Node\Expr\PropertyFetch,
                $expr instanceof Node\Expr\ArrayDimFetch => [$expr, 'var', null],
                ($expr instanceof Node\Expr\FuncCall && $expr->name instanceof Node\Name)
                    || ($expr instanceof Node\Expr\StaticCall && $expr->class instanceof Node\Name)
                    || ($expr instanceof Node\Expr\New_ && $expr->class instanceof Node\Name) => [$expr, 'args', 0],
                default => [null, '', null],
            };
            if ($holder === null) {
                return null;
            }
        }

        return null;
    }

    /**
     * Другие отслеживаемые объекты, переданные аргументами, могут измениться.
     *
     * @param array<string, ObjectState> $states
     */
    private function escapeArgs(Node\Expr\MethodCall $call, array &$states): void
    {
        foreach ($call->args as $arg) {
            if ($arg instanceof Node\Arg) {
                $this->escapeAll($arg->value, $states);
            }
        }
    }

    /**
     * Объекты, упомянутые в узле не как получатель отслеживаемого вызова, уходят в
     * неизвестный код: их нестабильное состояние сбрасывается.
     *
     * @param array<string, ObjectState> $states
     */
    private function escapeAll(Node $node, array &$states, ?string $except = null): void
    {
        if ($states === []) {
            return;
        }

        foreach (new NodeFinder()->findInstanceOf([$node], Node\Expr\Variable::class) as $var) {
            if (is_string($var->name) && $var->name !== $except && isset($states[$var->name])) {
                $states[$var->name] = $states[$var->name]->escaped();
            }
        }
    }

    // ── Конструирование ──────────────────────────────────────────────────────

    private function construct(Node\Expr\New_ $new): ?ObjectState
    {
        if (!$new->class instanceof Node\Name) {
            return null;
        }

        $class = $this->classes[strtolower($new->class->toString())] ?? null;
        if (!$class instanceof Node\Stmt\Class_) {
            return null;
        }

        $state = new ObjectState($class, $this->stableProps($class));
        foreach ($class->getProperties() as $property) {
            if ($property->isStatic()) {
                continue;
            }

            foreach ($property->props as $prop) {
                $default = $prop->default ?? ($property->type === null ? new Node\Expr\ConstFetch(new Node\Name('null')) : null);
                $known   = $default !== null && BodyInliner::isConstant($default);
                $state   = $state->with($prop->name->toString(), $known ? PropValue::of($default) : null);
            }
        }

        $ctor = $class->getMethod('__construct');
        if (!$ctor instanceof \PhpParser\Node\Stmt\ClassMethod) {
            return $new->args === [] ? $state : null;
        }

        foreach ($ctor->params as $param) {
            if (($param->flags & Modifiers::VISIBILITY_MASK) !== 0 && $param->var instanceof Node\Expr\Variable && is_string($param->var->name)) {
                $state = $state->with($param->var->name, null);
            }
        }

        $call = new Node\Expr\MethodCall(new Node\Expr\Variable('__new'), new Node\Identifier('__construct'), $new->args);
        $run  = $this->run($ctor, $call->args, '__new', $state, 'simulate', 0, promoted: true);

        return $run?->state;
    }

    /**
     * Свойства, которые пишутся только в конструкторе и в никогда не вызываемых методах.
     *
     * @return array<string, true>
     */
    private function stableProps(Node\Stmt\Class_ $class): array
    {
        $key = strtolower((string) $class->name);
        if (isset($this->stableProps[$key])) {
            return $this->stableProps[$key];
        }

        // Имена методов, которые где-либо вызываются. Динамический вызов ($obj->$name(),
        // call_user_func, [$obj, 'm'], 'Class::m') считаем способным попасть только в метод,
        // имя которого встречается в коде строковым литералом: имя должно откуда-то взяться.
        $called = [];
        foreach ($this->findNode(Node\Expr::class) as $expr) {
            if (
                ($expr instanceof Node\Expr\MethodCall || $expr instanceof Node\Expr\NullsafeMethodCall || $expr instanceof Node\Expr\StaticCall)
                && $expr->name instanceof Node\Identifier
            ) {
                $called[strtolower($expr->name->toString())] = true;
            } elseif ($expr instanceof Node\Scalar\String_) {
                $value                                   = strtolower($expr->value);
                $called[$value]                          = true;
                $called[substr(strrchr('::' . $value, ':') ?: '', 1)] = true;
            }
        }

        $props = [];
        foreach ($class->getProperties() as $property) {
            foreach ($property->props as $prop) {
                $props[$prop->name->toString()] = true;
            }
        }

        foreach ($class->getMethod('__construct')->params ?? [] as $param) {
            if (($param->flags & Modifiers::VISIBILITY_MASK) !== 0 && $param->var instanceof Node\Expr\Variable && is_string($param->var->name)) {
                $props[$param->var->name] = true;
            }
        }

        foreach ($class->getMethods() as $method) {
            $name = strtolower($method->name->toString());
            if ($name === '__construct' || (!isset($called[$name]) && !str_starts_with($name, '__'))) {
                continue;
            }

            foreach ($this->writtenThisProps($method->stmts ?? []) as $prop) {
                $props[$prop] = false;
            }
        }

        // Публичные свойства могут меняться снаружи
        foreach ($this->findNode(Node\Expr\PropertyFetch::class) as $fetch) {
            if ($fetch->name instanceof Node\Identifier && (!$fetch->var instanceof Node\Expr\Variable || $fetch->var->name !== 'this')) {
                $props[$fetch->name->toString()] = false;
            }
        }

        return $this->stableProps[$key] = array_filter($props);
    }

    /**
     * Свойства $this, которые код может изменить: присваивания (в том числе элементам
     * массива), инкременты, unset, передача в функцию (возможно по ссылке).
     *
     * @param  array<Node> $stmts
     * @return list<string>
     */
    private function writtenThisProps(array $stmts): array
    {
        $base = static function (?Node $target): ?string {
            while (
                $target instanceof Node\Expr\ArrayDimFetch
                || ($target instanceof Node\Expr\PropertyFetch && !($target->var instanceof Node\Expr\Variable))
            ) {
                $target = $target->var;
            }

            return $target instanceof Node\Expr\PropertyFetch
                && $target->var instanceof Node\Expr\Variable
                && $target->var->name === 'this'
                && $target->name instanceof Node\Identifier
                ? $target->name->toString()
                : null;
        };

        $props = [];
        foreach (new NodeFinder()->find($stmts, static fn(Node $n): bool => true) as $node) {
            $targets = match (true) {
                $node instanceof Node\Expr\Assign, $node instanceof Node\Expr\AssignOp, $node instanceof Node\Expr\AssignRef,
                $node instanceof Node\Expr\PreInc, $node instanceof Node\Expr\PreDec,
                $node instanceof Node\Expr\PostInc, $node instanceof Node\Expr\PostDec => [$node->var],
                $node instanceof Node\Stmt\Unset_ => $node->vars,
                $node instanceof Node\Stmt\Foreach_ => [$node->keyVar, $node->valueVar],
                $node instanceof Node\Expr\FuncCall => array_map(
                    static fn(int $i, $a): ?Node => $a instanceof Node\Arg && BodyInliner::mayPassByRef($node, $i) ? $a->value : null,
                    array_keys($node->args),
                    $node->args
                ),
                default => [],
            };
            foreach ($targets as $target) {
                $prop = $base($target);
                if ($prop !== null) {
                    $props[] = $prop;
                }
            }
        }

        return $props;
    }

    // ── Интерпретация ────────────────────────────────────────────────────────

    /** @param 'simulate'|'specialize' $mode */
    private function runCall(string $var, ObjectState $state, Node\Expr\MethodCall $call, string $mode): ?RunResult
    {
        if (!$call->name instanceof Node\Identifier) {
            return null;
        }

        $method = $state->class->getMethod($call->name->toString());
        if (!$method instanceof Node\Stmt\ClassMethod || $method->isStatic() || !$method->isPublic()) {
            return null;
        }

        return $this->run($method, $call->args, $var, $state, $mode, 0);
    }

    /**
     * Выполняет тело метода над состоянием объекта.
     *
     * simulate   — вызов остаётся в коде, считается только новое состояние;
     * specialize — вызов заменяется: побочные эффекты выводятся операторами,
     *              результат — выражение.
     *
     * @param array<Node\Arg|Node\VariadicPlaceholder> $args
     * @param 'simulate'|'specialize'                  $mode
     */
    private function run(
        Node\Stmt\ClassMethod $method,
        array $args,
        string $var,
        ObjectState $state,
        string $mode,
        int $depth,
        bool $promoted = false,
    ): ?RunResult {
        if ($depth > self::MAX_DEPTH || $method->stmts === null) {
            return null;
        }

        $ctx = new RunContext($var, $state, $mode, $method->name->toString(), $depth);

        foreach ($method->params as $i => $param) {
            if ($param->byRef || $param->variadic || !$param->var instanceof Node\Expr\Variable || !is_string($param->var->name)) {
                return null;
            }

            $arg = $args[$i] ?? null;
            if ($arg !== null && (!$arg instanceof Node\Arg || $arg->unpack || $arg->name instanceof Node\Identifier)) {
                return null;
            }

            $value = $arg instanceof Node\Arg ? $arg->value : $param->default;
            if ($value === null) {
                return null;
            }

            $ctx->env[$param->var->name] = $this->isKnownValue($value) ? $value : null;
            if ($promoted && ($param->flags & Modifiers::VISIBILITY_MASK) !== 0) {
                $arg        = $ctx->env[$param->var->name];
                $ctx->state = $ctx->state->with($param->var->name, $arg instanceof Node\Expr ? PropValue::of($arg) : null);
            }
        }

        if (count($args) > count($method->params)) {
            return null;
        }

        $flow = $this->execBlock($method->stmts, $ctx);
        if ($flow === false) {
            return null;
        }

        return new RunResult($ctx->state, $ctx->emitted, $flow === true ? new Node\Expr\ConstFetch(new Node\Name('null')) : $flow, $ctx->wrote);
    }

    /**
     * @param  array<Node\Stmt> $stmts
     * @return Node\Expr|bool  выражение — выполнен return; true — блок завершился; false — не удалось
     */
    private function execBlock(array $stmts, RunContext $ctx): Node\Expr|bool
    {
        foreach ($stmts as $stmt) {
            $flow = $this->execStmt($stmt, $ctx);
            if ($flow !== true) {
                return $flow;
            }
        }

        return true;
    }

    private function execStmt(Node\Stmt $stmt, RunContext $ctx): Node\Expr|bool
    {
        if ($stmt instanceof Node\Stmt\Nop) {
            return true;
        }

        if ($stmt instanceof Node\Stmt\Return_) {
            if (!$stmt->expr instanceof \PhpParser\Node\Expr) {
                return new Node\Expr\ConstFetch(new Node\Name('null'));
            }

            $value = $this->evalExpr($stmt->expr, $ctx);

            return $value ?? ($ctx->mode === 'simulate' ? new Node\Expr\ConstFetch(new Node\Name('null')) : false);
        }

        if ($stmt instanceof Node\Stmt\If_) {
            $branches = [[$stmt->cond, $stmt->stmts]];
            foreach ($stmt->elseifs as $elseif) {
                $branches[] = [$elseif->cond, $elseif->stmts];
            }

            foreach ($branches as [$cond, $body]) {
                $value = $this->evalExpr($cond, $ctx);
                $bool  = $this->constBool($value);
                if ($bool === null) {
                    return false;
                }

                if ($bool) {
                    return $this->execBlock($body, $ctx);
                }
            }

            return $stmt->else instanceof Node\Stmt\Else_ ? $this->execBlock($stmt->else->stmts, $ctx) : true;
        }

        if (!$stmt instanceof Node\Stmt\Expression) {
            return false;
        }

        $expr = $stmt->expr;

        // Запись в свойство: $this->p = v; $this->p[k] = v;
        if ($expr instanceof Node\Expr\Assign && ($target = $this->propTarget($expr->var)) !== null) {
            return $this->writeProp($target[0], $target[1], $expr->expr, $ctx);
        }

        // Локальная переменная
        if (
            $expr instanceof Node\Expr\Assign
            && $expr->var instanceof Node\Expr\Variable
            && is_string($expr->var->name)
            && $expr->var->name !== 'this'
        ) {
            $value = $this->evalExpr($expr->expr, $ctx);
            if ($ctx->mode === 'simulate') {
                $ctx->env[$expr->var->name] = $value instanceof \PhpParser\Node\Expr && $this->isKnownValue($value) ? $value : null;
                if (!$value instanceof \PhpParser\Node\Expr) {
                    $this->maybeEscape($expr->expr, $ctx);
                }

                return true;
            }

            if (!$value instanceof \PhpParser\Node\Expr) {
                return false;
            }

            if (!$this->isKnownValue($value)) {
                $tmp           = new Node\Expr\Variable($this->freshName($ctx->prefix . '_' . $expr->var->name));
                $ctx->emitted[] = new Node\Stmt\Expression(new Node\Expr\Assign($tmp, $value));
                $this->afterEmit($value, $ctx);
                $value = BodyInliner::clone($tmp);
            }

            $ctx->env[$expr->var->name] = $value;

            return true;
        }

        // Вызов собственного метода: $this->m(args);
        if ($expr instanceof Node\Expr\MethodCall && $expr->var instanceof Node\Expr\Variable && $expr->var->name === 'this') {
            if ($this->evalExpr($expr, $ctx) instanceof \PhpParser\Node\Expr) {
                return true;
            }

            $ctx->state = $ctx->state->escaped();

            return $ctx->mode === 'simulate';
        }

        // Прочее выражение-оператор
        if ($ctx->mode === 'simulate') {
            $this->maybeEscape($expr, $ctx);
            return true;
        }

        $value = $this->evalExpr($expr, $ctx);
        if (!$value instanceof \PhpParser\Node\Expr) {
            return false;
        }

        $ctx->emitted[] = new Node\Stmt\Expression($value);
        $this->afterEmit($value, $ctx);

        return true;
    }

    /** @return array{string, Node\Expr|null}|null  [свойство, ключ (null — запись целиком)] */
    private function propTarget(Node\Expr $target): ?array
    {
        if ($target instanceof Node\Expr\PropertyFetch && $this->isThis($target->var) && $target->name instanceof Node\Identifier) {
            return [$target->name->toString(), null];
        }

        if (
            $target instanceof Node\Expr\ArrayDimFetch
            && $target->dim instanceof Node\Expr
            && $target->var instanceof Node\Expr\PropertyFetch
            && $this->isThis($target->var->var)
            && $target->var->name instanceof Node\Identifier
        ) {
            return [$target->var->name->toString(), $target->dim];
        }

        return null;
    }

    private function writeProp(string $prop, ?Node\Expr $dim, Node\Expr $rhs, RunContext $ctx): bool
    {
        $value = $this->evalExpr($rhs, $ctx);
        $key   = $dim instanceof \PhpParser\Node\Expr ? $this->evalExpr($dim, $ctx) : null;
        $known = $value instanceof \PhpParser\Node\Expr && $this->isKnownValue($value);
        $keyOk = !$dim instanceof \PhpParser\Node\Expr || ($key instanceof Node\Scalar\String_ || $key instanceof Node\Scalar\Int_);

        if ($ctx->mode === 'specialize') {
            if (!$value instanceof \PhpParser\Node\Expr || ($dim instanceof \PhpParser\Node\Expr && !$key instanceof \PhpParser\Node\Expr)) {
                return false;
            }

            $write = $this->publicWrite($ctx, $prop, $key, $value);
            if (!$write instanceof \PhpParser\Node\Stmt) {
                return false;
            }

            $ctx->emitted[] = $write;
            $ctx->wrote     = true;
            if (!$known) {
                // Значение выводится в код как есть — в состоянии держим его копию,
                // только если это переменная (не вычисляется повторно)
                $known = $value instanceof Node\Expr\Variable;
            }
        }

        $current = $ctx->state->props[$prop] ?? null;
        if (!$dim instanceof \PhpParser\Node\Expr) {
            $ctx->state = $ctx->state->with($prop, $known && $value instanceof \PhpParser\Node\Expr ? PropValue::of($value) : null);
        } elseif (!$keyOk || !$key instanceof \PhpParser\Node\Expr) {
            $ctx->state = $ctx->state->with($prop, null);
        } else {
            // Массив мог быть неизвестен: после записи известен хотя бы этот ключ
            $base       = $current instanceof PropValue && $current->isArray() ? $current : PropValue::unknownArray();
            $next       = $known && $value instanceof Node\Expr ? $base->withItem($key, $value) : $base->withUnknownItem($key);
            $ctx->state = $ctx->state->with($prop, $next);
        }

        return true;
    }

    /**
     * Запись в свойство объекта снаружи класса: напрямую для публичного свойства,
     * иначе через публичный метод-сеттер, который делает ровно эту запись.
     */
    private function publicWrite(RunContext $ctx, string $prop, ?Node\Expr $key, Node\Expr $value): ?Node\Stmt
    {
        $object = new Node\Expr\Variable($ctx->var);
        $class  = $ctx->state->class;
        foreach ($class->getProperties() as $property) {
            foreach ($property->props as $item) {
                if ($item->name->toString() === $prop && $property->isPublic() && !$property->isReadonly()) {
                    $fetch = new Node\Expr\PropertyFetch($object, $prop);
                    $lhs   = $key instanceof \PhpParser\Node\Expr ? new Node\Expr\ArrayDimFetch($fetch, BodyInliner::clone($key)) : $fetch;
                    return new Node\Stmt\Expression(new Node\Expr\Assign($lhs, BodyInliner::clone($value)));
                }
            }
        }

        foreach ($class->getMethods() as $method) {
            if (!$method->isPublic() || $method->isStatic() || count($method->stmts ?? []) !== 1) {
                continue;
            }

            $only = $method->stmts[0] ?? null;
            if (!$only instanceof Node\Stmt\Expression || !$only->expr instanceof Node\Expr\Assign) {
                continue;
            }

            $target = $this->propTarget($only->expr->var);
            $params = array_map(
                static fn(Node\Param $p): ?string => $p->var instanceof Node\Expr\Variable && is_string($p->var->name) ? $p->var->name : null,
                $method->params
            );
            if ($target === null || $target[0] !== $prop || !$only->expr->expr instanceof Node\Expr\Variable) {
                continue;
            }

            $valueParam = $only->expr->expr->name;
            if (!$key instanceof \PhpParser\Node\Expr && $target[1] === null && $params === [$valueParam]) {
                return new Node\Stmt\Expression(
                    new Node\Expr\MethodCall($object, $method->name->toString(), [new Node\Arg(BodyInliner::clone($value))])
                );
            }

            if (
                $key instanceof \PhpParser\Node\Expr
                && $target[1] instanceof Node\Expr\Variable
                && count($params) === 2
                && $params === [$target[1]->name, $valueParam]
            ) {
                return new Node\Stmt\Expression(new Node\Expr\MethodCall(
                    $object,
                    $method->name->toString(),
                    [new Node\Arg(BodyInliner::clone($key)), new Node\Arg(BodyInliner::clone($value))]
                ));
            }
        }

        return null;
    }

    /**
     * Вычисляет выражение тела метода: подставляет параметры, локальные переменные
     * и известные свойства, сворачивает проверки. null — значение неизвестно.
     */
    private function evalExpr(Node\Expr $expr, RunContext $ctx): ?Node\Expr
    {
        if ($expr instanceof Node\Expr\Variable && is_string($expr->name)) {
            if ($expr->name === 'this') {
                return new Node\Expr\Variable($ctx->var);
            }

            $value = $ctx->env[$expr->name] ?? null;

            return $value !== null ? BodyInliner::clone($value) : null;
        }

        if ($expr instanceof Node\Expr\PropertyFetch && $this->isThis($expr->var) && $expr->name instanceof Node\Identifier) {
            return ($ctx->state->props[$expr->name->toString()] ?? null)?->toExpr();
        }

        if (
            $expr instanceof Node\Expr\ArrayDimFetch
            && $expr->dim instanceof Node\Expr
            && $expr->var instanceof Node\Expr\PropertyFetch
            && $this->isThis($expr->var->var)
            && $expr->var->name instanceof Node\Identifier
        ) {
            $key  = $this->evalExpr($expr->dim, $ctx);
            $prop = $ctx->state->props[$expr->var->name->toString()] ?? null;

            return $key instanceof \PhpParser\Node\Expr && $prop instanceof PropValue ? $prop->item($key) : null;
        }

        if ($expr instanceof Node\Expr\MethodCall && $this->isThis($expr->var) && $expr->name instanceof Node\Identifier) {
            $method = $ctx->state->class->getMethod($expr->name->toString());
            if (!$method instanceof Node\Stmt\ClassMethod || $method->isStatic()) {
                return null;
            }

            $args = [];
            foreach ($expr->args as $arg) {
                if (!$arg instanceof Node\Arg || $arg->unpack || $arg->name instanceof Node\Identifier) {
                    return null;
                }

                $value = $this->evalExpr($arg->value, $ctx);
                if (!$value instanceof \PhpParser\Node\Expr) {
                    return null;
                }

                $args[] = new Node\Arg($value);
            }

            $run = $this->run($method, $args, $ctx->var, $ctx->state, $ctx->mode, $ctx->depth + 1);
            if (!$run instanceof \Recombinator\Transformation\State\RunResult) {
                // Метод мог успеть изменить состояние — считаем его неизвестным
                $ctx->state = $ctx->state->escaped();
                return null;
            }

            $ctx->state = $run->state;
            array_push($ctx->emitted, ...$run->emitted);
            $ctx->wrote = $ctx->wrote || $run->wrote;

            return $run->value;
        }

        // array_key_exists('k', $this->p) / key_exists
        if (
            $expr instanceof Node\Expr\FuncCall
            && $expr->name instanceof Node\Name
            && in_array(strtolower($expr->name->getLast()), ['array_key_exists', 'key_exists'], true)
            && count($expr->args) === 2
            && $expr->args[0] instanceof Node\Arg
            && $expr->args[1] instanceof Node\Arg
        ) {
            $key = $this->evalExpr($expr->args[0]->value, $ctx);
            $arr = $expr->args[1]->value;
            if (
                $key instanceof Node\Expr
                && $arr instanceof Node\Expr\PropertyFetch
                && $this->isThis($arr->var)
                && $arr->name instanceof Node\Identifier
            ) {
                $has = ($ctx->state->props[$arr->name->toString()] ?? null)?->hasKey($key);
                if ($has !== null) {
                    return $this->bool($has);
                }
            }
        }

        if ($expr instanceof Node\Expr\BooleanNot) {
            $inner = $this->evalExpr($expr->expr, $ctx);
            $bool  = $this->constBool($inner);

            return $bool !== null ? $this->bool(!$bool) : ($inner instanceof \PhpParser\Node\Expr ? new Node\Expr\BooleanNot($inner) : null);
        }

        if ($expr instanceof Node\Expr\Instanceof_ && $expr->class instanceof Node\Name) {
            $inner = $this->evalExpr($expr->expr, $ctx);
            if (!$inner instanceof \PhpParser\Node\Expr) {
                return null;
            }

            $isClosureClass = strtolower(ltrim($expr->class->toString(), '\\')) === 'closure';
            if ($inner instanceof Node\Expr\Closure || $inner instanceof Node\Expr\ArrowFunction) {
                return $this->bool($isClosureClass);
            }

            if ($inner instanceof Node\Expr\Array_ || $inner instanceof Node\Scalar || $inner instanceof Node\Expr\ConstFetch) {
                return $this->bool(false);
            }

            return new Node\Expr\Instanceof_($inner, $expr->class);
        }

        if (
            $expr instanceof Node\Expr\Throw_
            || $expr instanceof Node\Expr\Exit_
            || $expr instanceof Node\Expr\Include_
            || $expr instanceof Node\Expr\Eval_
        ) {
            return null;
        }

        if ($expr instanceof Node\Expr\Closure || $expr instanceof Node\Expr\ArrowFunction) {
            // Литерал замыкания копируется как есть; захват переменных метода не поддерживаем
            return BodyInliner::isConstant($expr) || ($expr instanceof Node\Expr\ArrowFunction && $expr->static)
                ? BodyInliner::clone($expr)
                : null;
        }

        // Остальное: подставляем значения в подвыражения
        $copy = clone $expr;
        foreach ($copy->getSubNodeNames() as $sub) {
            $value = $copy->$sub;
            if ($value instanceof Node\Expr) {
                // Сначала проверяем: в типизированное свойство узла null записать нельзя
                $inner = $this->evalExpr($value, $ctx);
                if (!$inner instanceof Node\Expr) {
                    return null;
                }

                $copy->$sub = $inner;
            } elseif (is_array($value)) {
                $items = [];
                foreach ($value as $k => $item) {
                    if ($item instanceof Node\Arg) {
                        $inner = $this->evalExpr($item->value, $ctx);
                        if (!$inner instanceof \PhpParser\Node\Expr) {
                            return null;
                        }

                        $item        = clone $item;
                        $item->value = $inner;
                    } elseif ($item instanceof Node\ArrayItem) {
                        $inner = $this->evalExpr($item->value, $ctx);
                        $key   = $item->key instanceof \PhpParser\Node\Expr ? $this->evalExpr($item->key, $ctx) : null;
                        if (!$inner instanceof Node\Expr || ($item->key instanceof Node\Expr && !$key instanceof Node\Expr)) {
                            return null;
                        }

                        $item        = clone $item;
                        $item->value = $inner;
                        $item->key   = $key;
                    } elseif ($item instanceof Node\Expr) {
                        $item = $this->evalExpr($item, $ctx);
                        if (!$item instanceof \PhpParser\Node\Expr) {
                            return null;
                        }
                    } elseif ($item instanceof Node && !$item instanceof Node\Name && !$item instanceof Node\Identifier) {
                        return null;
                    }

                    $items[$k] = $item;
                }

                $copy->$sub = $items;
            } elseif ($value instanceof Node && !$value instanceof Node\Name && !$value instanceof Node\Identifier) {
                return null;
            }
        }

        return $copy;
    }

    /**
     * Выведенный оператор передаёт объект наружу — нестабильное состояние сбрасывается.
     */
    private function afterEmit(Node\Expr $emitted, RunContext $ctx): void
    {
        foreach (new NodeFinder()->findInstanceOf([$emitted], Node\Expr\Variable::class) as $var) {
            if ($var->name === $ctx->var) {
                $ctx->state = $ctx->state->escaped();
                return;
            }
        }
    }

    private function maybeEscape(Node\Expr $expr, RunContext $ctx): void
    {
        if (BodyInliner::usesThis([new Node\Stmt\Expression($expr)])) {
            $ctx->state = $ctx->state->escaped();
        }
    }

    /**
     * Значение можно держать в состоянии и подставлять позже: константа или переменная,
     * которая больше не перезаписывается.
     */
    private function isKnownValue(Node\Expr $expr): bool
    {
        if (BodyInliner::isConstant($expr)) {
            return true;
        }

        return $expr instanceof Node\Expr\Variable
            && is_string($expr->name)
            && (isset($this->freshVars[$expr->name]) || ($this->assignCount[$expr->name] ?? 0) === 1);
    }

    private function isThis(Node\Expr $expr): bool
    {
        return $expr instanceof Node\Expr\Variable && $expr->name === 'this';
    }

    private function freshName(string $base): string
    {
        do {
            $name = $base . '_' . (++$this->counter);
        } while (isset($this->usedVars[$name]));

        $this->usedVars[$name]  = true;
        $this->freshVars[$name] = true;

        return $name;
    }

    private function constBool(?Node\Expr $expr): ?bool
    {
        if ($expr instanceof Node\Expr\ConstFetch) {
            return match (strtolower($expr->name->toString())) {
                'true' => true,
                'false' => false,
                default => null,
            };
        }

        return null;
    }

    private function bool(bool $value): Node\Expr\ConstFetch
    {
        return new Node\Expr\ConstFetch(new Node\Name($value ? 'true' : 'false'));
    }
}
