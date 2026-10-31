<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use Recombinator\Support\TypeInference;

/**
 * Класс с одним методом (не считая конструктора, который только сохраняет параметры в
 * свойства) — замыкание:
 *
 *   class ServerRequestCreator {
 *       public function __construct(private $creator, private string $method) {}
 *       public function createServerRequestFromGlobals() { return \Closure::fromCallable([$this->creator, $this->method])(); }
 *   }
 *   $c = new ServerRequestCreator($nyholm, 'fromGlobals');  …  $c->createServerRequestFromGlobals()
 *   →
 *   $c = (static fn($creator, $method) => static fn() => \Closure::fromCallable([$creator, $method])())($nyholm, 'fromGlobals');
 *   …  $c()
 *
 * Условия: метод только читает свойства (`$this->p`), иначе `$this` не используется; объект
 * класса (`new C(…)`) попадает только в получателя вызова этого метода — через локальные
 * переменные, возврат из функций и методов (по их местам вызова), аргументы (по
 * использованию параметра), свойства-носители; класс не назван в instanceof, `C::class`,
 * строках, catch; у класса нет предков и наследников, статических членов и атрибутов.
 */
#[VisitorMeta('Класс с одним методом → замыкание', targets: [Node\Stmt\Class_::class])]
class ClosureClassVisitor extends BaseVisitor
{
    /** @var array<string, array{Node\Stmt\Class_, Node\Stmt\ClassMethod, list<string>, array<string, Node\Expr>}> класс → [класс, метод, параметры конструктора, свойства по умолчанию] */
    private array $plans = [];

    /** @var \SplObjectStorage<Node\Expr\MethodCall, true> вызовы метода, которые станут вызовом замыкания */
    private \SplObjectStorage $calls;

    /** @var array<int, bool> */
    private array $safe = [];

    /** @var array<int, true> */
    private array $inProgress = [];

    private ?TypeInference $inference = null;

    /** @var array<string, list<Node\Expr\CallLike>>|null */
    private ?array $callSites = null;

    /** @var array<string, list<Node\FunctionLike>>|null */
    private ?array $declarations = null;

    /** @var array<string, list<Node\Expr>>|null */
    private ?array $propertyFetches = null;

    public function __construct()
    {
        $this->calls = new \SplObjectStorage();
    }

    #[\Override]
    public function beforeTraverse(array $nodes): ?array
    {
        parent::beforeTraverse($nodes);
        $this->plans           = [];
        $this->calls           = new \SplObjectStorage();
        $this->safe            = [];
        $this->callSites       = null;
        $this->declarations    = null;
        $this->propertyFetches = null;
        if ($this->hasPendingIncludes()) {
            return null;
        }

        $classes = [];
        foreach ($this->findNode(Node\Stmt\ClassLike::class) as $class) {
            if ($class->name instanceof Node\Identifier) {
                $classes[$class->name->toLowerString()] = $class;
            }
        }

        $this->inference = new TypeInference($classes, $nodes);
        $named           = $this->namedClasses($nodes);
        foreach ($nodes as $class) {
            if (!$class instanceof Node\Stmt\Class_ || !$class->name instanceof Node\Identifier || isset($named[$class->name->toLowerString()])) {
                continue;
            }

            $shape = $this->shape($class, $classes);
            if ($shape === null) {
                continue;
            }

            [$method, $params, $defaults] = $shape;
            $calls = $this->objectCalls($class, $method);
            if ($calls === null) {
                continue;
            }

            $this->plans[$class->name->toLowerString()] = [$class, $method, $params, $defaults];
            foreach ($calls as $call) {
                $this->calls[$call] = true;
            }
        }

        return null;
    }

    public function enterNode(Node $node): ?Node
    {
        if ($node instanceof Node\Expr\New_ && $node->class instanceof Node\Name) {
            $plan = $this->plans[strtolower(ltrim($node->class->toString(), '\\'))] ?? null;

            return $plan === null ? null : $this->closure($plan, $node);
        }

        if ($node instanceof Node\Expr\MethodCall && isset($this->calls[$node])) {
            return new Node\Expr\FuncCall($node->var, $node->args, $node->getAttributes());
        }

        // Тип-подсказка класса → \Closure
        if (($node instanceof Node\Param || $node instanceof Node\Stmt\Property) && $node->type instanceof \PhpParser\Node) {
            $node->type = $this->closureType($node->type);
        } elseif ($node instanceof Node\FunctionLike && !$node instanceof Node\Expr\ArrowFunction && $node->getReturnType() !== null) {
            $node->returnType = $this->closureType($node->getReturnType());
        }

        return null;
    }

    public function leaveNode(Node $node): int|Node|array|null
    {
        if ($node instanceof Node\Stmt\Class_ && $node->name instanceof Node\Identifier && isset($this->plans[$node->name->toLowerString()])) {
            return NodeTraverser::REMOVE_NODE;
        }

        return parent::leaveNode($node);
    }

    private function closureType(Node\ComplexType|Node\Identifier|Node\Name $type): Node\ComplexType|Node\Identifier|Node\Name
    {
        $lower = static fn(Node $n): ?string => $n instanceof Node\Name ? strtolower(ltrim($n->toString(), '\\')) : null;
        if ($type instanceof Node\Name && isset($this->plans[$lower($type)])) {
            return new Node\Name\FullyQualified('Closure');
        }

        if ($type instanceof Node\NullableType && isset($this->plans[$lower($type->type) ?? ''])) {
            return new Node\NullableType(new Node\Name\FullyQualified('Closure'));
        }

        if ($type instanceof Node\UnionType) {
            foreach ($type->types as $index => $part) {
                if (isset($this->plans[$lower($part) ?? ''])) {
                    $type->types[$index] = new Node\Name\FullyQualified('Closure');
                }
            }
        }

        return $type;
    }

    /**
     * `new C(a, b)` → `(static fn($p1, $p2) => static fn(…) => …)(a, b)`; тело из нескольких
     * инструкций — `static function (…) use ($p1, $p2) { … }`.
     *
     * @param array{Node\Stmt\Class_, Node\Stmt\ClassMethod, list<string>, array<string, Node\Expr>} $plan
     */
    private function closure(array $plan, Node\Expr\New_ $new): \PhpParser\Node\Expr
    {
        [, $method, $params, $defaults] = $plan;
        $body = [];
        foreach ($method->stmts ?? [] as $stmt) {
            $copy = $this->deepClone($stmt);
            assert($copy instanceof Node\Stmt);
            $body[] = $copy;
        }

        // $this->p → $p (параметр конструктора) или значение свойства по умолчанию
        $body = new NodeTraverser(new class ($params, $defaults, $this) extends NodeVisitorAbstract {
            /**
             * @param list<string>              $params
             * @param array<string, Node\Expr>  $defaults
             */
            public function __construct(private readonly array $params, private readonly array $defaults, private readonly ClosureClassVisitor $owner)
            {
            }

            public function leaveNode(Node $node): ?Node
            {
                if (
                    !$node instanceof Node\Expr\PropertyFetch || !$node->var instanceof Node\Expr\Variable || $node->var->name !== 'this'
                    || !$node->name instanceof Node\Identifier
                ) {
                    return null;
                }

                $name = $node->name->toString();
                if (in_array($name, $this->params, true)) {
                    return new Node\Expr\Variable($name);
                }

                return isset($this->defaults[$name]) ? $this->owner->copy($this->defaults[$name]) : null;
            }
        })->traverse($body);

        // Параметры конструктора — с типами (приведение) и значениями по умолчанию, без повышения
        $outer = [];
        foreach ($plan[0]->getMethod('__construct')->params ?? [] as $param) {
            $copy             = $this->copyParam($param);
            $copy->flags      = 0;
            $copy->attrGroups = [];
            $outer[]          = $copy;
        }

        $inner = array_map($this->copyParam(...), $method->params);
        $only  = count($body) === 1 ? $body[0] : null;
        $fn    = $only instanceof Node\Stmt\Return_ && $only->expr instanceof Node\Expr
            ? new Node\Expr\ArrowFunction(['static' => true, 'params' => $inner, 'expr' => $only->expr, 'returnType' => $method->returnType])
            : new Node\Expr\Closure([
                'static'     => true,
                'params'     => $inner,
                'uses'       => array_map(static fn(string $p): Node\ClosureUse => new Node\ClosureUse(new Node\Expr\Variable($p)), $params),
                'stmts'      => array_values(array_filter($body, static fn(Node $n): bool => $n instanceof Node\Stmt)),
                'returnType' => $method->returnType,
            ]);

        if ($params === []) {
            return $fn;
        }

        return new Node\Expr\FuncCall(
            new Node\Expr\ArrowFunction(['static' => true, 'params' => $outer, 'expr' => $fn]),
            $new->args,
            $new->getAttributes(),
        );
    }

    public function copy(Node $node): Node
    {
        return $this->deepClone($node);
    }

    private function copyParam(Node\Param $param): Node\Param
    {
        $copy = $this->deepClone($param);
        assert($copy instanceof Node\Param);

        return $copy;
    }

    /**
     * Метод, параметры конструктора (в порядке) и значения свойств по умолчанию; null —
     * класс не подходит.
     *
     * @param  array<string, Node\Stmt\ClassLike> $classes
     * @return array{Node\Stmt\ClassMethod, list<string>, array<string, Node\Expr>}|null
     */
    private function shape(Node\Stmt\Class_ $class, array $classes): ?array
    {
        if ($class->extends instanceof \PhpParser\Node\Name || $class->implements !== [] || $class->attrGroups !== [] || $class->isAbstract()) {
            return null;
        }

        $name = strtolower((string) $class->name);
        foreach ($classes as $other) {
            if ($other instanceof Node\Stmt\Class_ && $other->extends instanceof Node\Name && strtolower(ltrim($other->extends->toString(), '\\')) === $name) {
                return null;
            }
        }

        $constructor = null;
        $method      = null;
        $properties  = [];
        foreach ($class->stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\ClassMethod) {
                if ($stmt->isStatic() || $stmt->attrGroups !== []) {
                    return null;
                }

                if ($stmt->name->toLowerString() === '__construct') {
                    $constructor = $stmt;
                } elseif (!$method instanceof \PhpParser\Node\Stmt\ClassMethod && $stmt->isPublic() && !str_starts_with($stmt->name->toString(), '__') && !$stmt->byRef) {
                    $method = $stmt;
                } else {
                    return null;
                }
            } elseif ($stmt instanceof Node\Stmt\Property && !$stmt->isStatic() && $stmt->attrGroups === [] && $stmt->hooks === []) {
                foreach ($stmt->props as $prop) {
                    $properties[$prop->name->toString()] = $prop->default;
                }
            } elseif (!$stmt instanceof Node\Stmt\Nop) {
                return null; // константы, трейты, статические свойства
            }
        }

        if (!$method instanceof Node\Stmt\ClassMethod || $method->stmts === null) {
            return null;
        }

        $constructorParams = $constructor instanceof Node\Stmt\ClassMethod ? $constructor->params : [];
        $constructorStmts  = $constructor instanceof Node\Stmt\ClassMethod ? $constructor->stmts ?? [] : [];

        // Конструктор: только `$this->p = $p;` (или повышенные параметры), без значений по умолчанию с вычислением
        $params = [];
        foreach ($constructorParams as $param) {
            if (!$param->var instanceof Node\Expr\Variable || !is_string($param->var->name) || $param->byRef || $param->variadic || $param->attrGroups !== []) {
                return null;
            }

            if ($param->flags !== 0) {
                if (($param->flags & \PhpParser\Modifiers::READONLY) === 0 && ($param->flags & (\PhpParser\Modifiers::PUBLIC | \PhpParser\Modifiers::PROTECTED | \PhpParser\Modifiers::PRIVATE)) === 0) {
                    return null;
                }

                $properties[$param->var->name] = null;
            }

            $params[] = $param->var->name;
        }

        foreach ($constructorStmts as $stmt) {
            $assign = $stmt instanceof Node\Stmt\Expression ? $stmt->expr : null;
            if (
                !$assign instanceof Node\Expr\Assign || !$assign->var instanceof Node\Expr\PropertyFetch || !$assign->var->var instanceof Node\Expr\Variable
                || $assign->var->var->name !== 'this' || !$assign->var->name instanceof Node\Identifier || !$assign->expr instanceof Node\Expr\Variable
                || $assign->expr->name !== $assign->var->name->toString() || !in_array($assign->expr->name, $params, true)
            ) {
                return null;
            }
        }

        // Метод: $this — только чтение свойств, без self/static/parent
        $defaults = [];
        foreach (new NodeFinder()->find($method->stmts, static fn(Node $n): bool => true) as $node) {
            if ($node instanceof Node\Expr\Variable && $node->name === 'this') {
                $parent = $node->getAttribute('parent');
                if (!$parent instanceof Node\Expr\PropertyFetch || !$parent->name instanceof Node\Identifier || $this->written($parent)) {
                    return null;
                }

                $property = $parent->name->toString();
                if (!array_key_exists($property, $properties)) {
                    return null;
                }

                if (!in_array($property, $params, true)) {
                    $default = $properties[$property];
                    if (!$default instanceof Node\Expr || !$this->isLiteral($default)) {
                        return null;
                    }

                    $defaults[$property] = $default;
                }
            } elseif (
                $node instanceof Node\Name && in_array($node->toLowerString(), ['self', 'static', 'parent'], true)
                || $node instanceof Node\Scalar\MagicConst || $node instanceof Node\Expr\Yield_ || $node instanceof Node\Expr\YieldFrom
                || $node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name && in_array($node->name->toLowerString(), ['func_get_args', 'get_class', 'get_called_class', 'compact', 'extract'], true)
            ) {
                return null;
            }
        }

        return [$method, $params, $defaults];
    }

    private function written(Node\Expr\PropertyFetch $fetch): bool
    {
        $child = $fetch;
        for ($parent = $fetch->getAttribute('parent'); $parent instanceof Node\Expr\ArrayDimFetch && $parent->var === $child; $parent = $parent->getAttribute('parent')) {
            $child = $parent;
        }

        return $parent instanceof Node\Expr\Assign && $parent->var === $child || $parent instanceof Node\Expr\AssignOp && $parent->var === $child
            || $parent instanceof Node\Expr\AssignRef || $parent instanceof Node\Stmt\Unset_ || $parent instanceof Node\Arg && $parent->byRef
            || $parent instanceof Node\Expr\PreInc || $parent instanceof Node\Expr\PostInc || $parent instanceof Node\Expr\PreDec || $parent instanceof Node\Expr\PostDec;
    }

    private function isLiteral(Node\Expr $expr): bool
    {
        return $expr instanceof Node\Scalar && !$expr instanceof Node\Scalar\InterpolatedString
            || $expr instanceof Node\Expr\ConstFetch
            || $expr instanceof Node\Expr\Array_ && array_all($expr->items, fn(Node\ArrayItem $i): bool => !$i->unpack && $this->isLiteral($i->value)
                && (!$i->key instanceof Node\Expr || $this->isLiteral($i->key)));
    }

    /**
     * Классы, названные не через `new`: строкой, `C::…`, instanceof, catch, extends.
     *
     * @param  array<Node> $nodes
     * @return array<string, true>
     */
    private function namedClasses(array $nodes): array
    {
        $names = [];
        foreach (new NodeFinder()->find($nodes, static fn(Node $n): bool => true) as $node) {
            match (true) {
                $node instanceof Node\Scalar\String_ => $names[strtolower(ltrim(explode('::', $node->value, 2)[0], '\\'))] = true,
                ($node instanceof Node\Expr\ClassConstFetch || $node instanceof Node\Expr\StaticCall || $node instanceof Node\Expr\StaticPropertyFetch
                    || $node instanceof Node\Expr\Instanceof_) && $node->class instanceof Node\Name => $names[strtolower(ltrim($node->class->toString(), '\\'))] = true,
                $node instanceof Node\Stmt\Catch_ => array_map(static function (Node\Name $n) use (&$names): void {
                    $names[strtolower(ltrim($n->toString(), '\\'))] = true;
                }, $node->types),
                default => null,
            };
        }

        return $names;
    }

    /**
     * Все вызовы метода на объектах класса; null — объект попадает куда-то ещё.
     *
     * @return list<Node\Expr\MethodCall>|null
     */
    private function objectCalls(Node\Stmt\Class_ $class, Node\Stmt\ClassMethod $method): ?array
    {
        $this->safe       = [];
        $this->inProgress = [];
        $this->found      = [];
        $this->method     = $method->name->toLowerString();
        $name             = strtolower((string) $class->name);
        $news             = new NodeFinder()->find($this->ast, static fn(Node $n): bool => $n instanceof Node\Expr\New_ && $n->class instanceof Node\Name
            && strtolower(ltrim($n->class->toString(), '\\')) === $name);
        if ($news === []) {
            return null;
        }

        foreach ($news as $new) {
            assert($new instanceof Node\Expr);
            if (!$this->valueSafe($new, 0)) {
                return null;
            }
        }

        return $this->foundCalls();
    }

    /** @return list<Node\Expr\MethodCall> */
    private function foundCalls(): array
    {
        return array_values($this->found);
    }

    /** @var array<int, Node\Expr\MethodCall> */
    private array $found = [];

    private string $method = '';

    private function valueSafe(Node\Expr $expr, int $depth): bool
    {
        if ($depth > 30) {
            return false;
        }

        $key = spl_object_id($expr);
        if (isset($this->safe[$key])) {
            return $this->safe[$key];
        }

        if (isset($this->inProgress[$key])) {
            return true;
        }

        $this->inProgress[$key] = true;
        $result                 = $this->context($expr, $depth + 1);
        unset($this->inProgress[$key]);

        return $this->safe[$key] = $result;
    }

    private function context(Node\Expr $expr, int $depth): bool
    {
        $parent = $expr->getAttribute('parent');
        if ($parent instanceof Node\Expr\MethodCall && $parent->var === $expr) {
            if (!$parent->name instanceof Node\Identifier || $parent->name->toLowerString() !== $this->method || $parent->isFirstClassCallable()) {
                return false;
            }

            $this->found[spl_object_id($parent)] = $parent;

            return true;
        }

        return match (true) {
            $parent instanceof Node\Stmt\Expression => true,
            $parent instanceof Node\Expr\Ternary => $parent->cond !== $expr && $this->valueSafe($parent, $depth),
            $parent instanceof Node\Expr\BinaryOp\Coalesce => $this->valueSafe($parent, $depth),
            $parent instanceof Node\Expr\Assign => $parent->expr === $expr && $this->assignedSafe($parent, $depth),
            $parent instanceof Node\Arg => $this->argumentSafe($parent, $depth),
            $parent instanceof Node\Stmt\Return_ => $this->returnSafe($parent, $depth),
            default => false,
        };
    }

    private function assignedSafe(Node\Expr\Assign $assign, int $depth): bool
    {
        if ($assign->var instanceof Node\Expr\Variable && is_string($assign->var->name)) {
            return $this->variableSafe($this->inference?->enclosingFunction($assign), $assign->var->name, $depth);
        }

        if ($assign->var instanceof Node\Expr\PropertyFetch && $assign->var->name instanceof Node\Identifier) {
            return $this->propertySafe($assign->var->name->toString(), $depth);
        }

        return false;
    }

    private function variableSafe(?Node\FunctionLike $scope, string $name, int $depth): bool
    {
        $nodes = $scope instanceof Node\FunctionLike ? $scope->getStmts() ?? [] : $this->ast;
        foreach (new NodeFinder()->find($nodes, static fn(Node $n): bool => $n instanceof Node\Expr\Variable && $n->name === $name) as $use) {
            assert($use instanceof Node\Expr\Variable);
            $parent = $use->getAttribute('parent');
            if ($parent instanceof Node\Expr\Assign && $parent->var === $use) {
                continue;
            }

            if ($parent instanceof Node\Expr\ClosureUse || !$this->valueSafe($use, $depth)) {
                return false;
            }
        }

        return true;
    }

    private function propertySafe(string $name, int $depth): bool
    {
        if ($this->propertyFetches === null) {
            $this->propertyFetches = [];
            foreach (new NodeFinder()->find($this->ast, static fn(Node $n): bool => $n instanceof Node\Expr\PropertyFetch || $n instanceof Node\Expr\NullsafePropertyFetch) as $fetch) {
                assert($fetch instanceof Node\Expr\PropertyFetch || $fetch instanceof Node\Expr\NullsafePropertyFetch);
                $this->propertyFetches[$fetch->name instanceof Node\Identifier ? $fetch->name->toString() : ''][] = $fetch;
            }
        }

        if (isset($this->propertyFetches[''])) {
            return false;
        }

        foreach ($this->propertyFetches[$name] ?? [] as $fetch) {
            $parent = $fetch->getAttribute('parent');
            if ($parent instanceof Node\Expr\Assign && $parent->var === $fetch) {
                continue;
            }

            if (!$this->valueSafe($fetch, $depth)) {
                return false;
            }
        }

        return true;
    }

    private function argumentSafe(Node\Arg $arg, int $depth): bool
    {
        $call     = $arg->getAttribute('parent');
        $position = $call instanceof Node\Expr\CallLike ? array_search($arg, $call->args, true) : false;
        if (!is_int($position) || $arg->unpack || $arg->byRef || $arg->name instanceof Node\Identifier) {
            return false;
        }

        $callees = $this->callees($call);
        if ($callees === null || $callees === []) {
            return false;
        }

        foreach ($callees as $callee) {
            $param = $callee->getParams()[$position] ?? null;
            if ($param === null) {
                continue;
            }

            if ($param->variadic || $param->byRef || !$param->var instanceof Node\Expr\Variable || !is_string($param->var->name)) {
                return false;
            }

            if ($param->flags !== 0 ? !$this->propertySafe($param->var->name, $depth) : !$this->variableSafe($callee, $param->var->name, $depth)) {
                return false;
            }
        }

        return true;
    }

    private function returnSafe(Node\Stmt\Return_ $return, int $depth): bool
    {
        $function = $this->inference?->enclosingFunction($return);
        if (!$function instanceof Node\Stmt\Function_ && !$function instanceof Node\Stmt\ClassMethod) {
            return false;
        }

        $this->index();
        $key = $function instanceof Node\Stmt\Function_ ? 'fn:' . $function->name->toLowerString() : $function->name->toLowerString();
        if (str_starts_with($key, '__')) {
            return false;
        }

        foreach ($this->callSites[$key] ?? [] as $site) {
            if (!$this->valueSafe($site, $depth)) {
                return false;
            }
        }

        return true;
    }

    /** @return list<Node\FunctionLike>|null */
    private function callees(mixed $call): ?array
    {
        $this->index();

        return match (true) {
            $call instanceof Node\Expr\FuncCall && $call->name instanceof Node\Name => $this->declarations['fn:' . strtolower(ltrim($call->name->toString(), '\\'))] ?? null,
            ($call instanceof Node\Expr\MethodCall || $call instanceof Node\Expr\StaticCall) && $call->name instanceof Node\Identifier
                => $this->declarations[$call->name->toLowerString()] ?? null,
            $call instanceof Node\Expr\New_ && $call->class instanceof Node\Name => $this->declarations['new:' . strtolower(ltrim($call->class->toString(), '\\'))] ?? null,
            default => null,
        };
    }

    private function index(): void
    {
        if ($this->callSites !== null) {
            return;
        }

        $this->callSites    = [];
        $this->declarations = [];
        foreach (new NodeFinder()->find($this->ast, static fn(Node $n): bool => $n instanceof Node\Expr\CallLike || $n instanceof Node\FunctionLike) as $node) {
            if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name) {
                $this->callSites['fn:' . strtolower(ltrim($node->name->toString(), '\\'))][] = $node;
            } elseif (($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\StaticCall || $node instanceof Node\Expr\NullsafeMethodCall) && $node->name instanceof Node\Identifier) {
                $this->callSites[$node->name->toLowerString()][] = $node;
            } elseif ($node instanceof Node\Stmt\Function_) {
                $this->declarations['fn:' . $node->name->toLowerString()][] = $node;
            } elseif ($node instanceof Node\Stmt\ClassMethod) {
                $this->declarations[$node->name->toLowerString()][] = $node;
                $owner = $node->getAttribute('parent');
                if ($node->name->toLowerString() === '__construct' && $owner instanceof Node\Stmt\ClassLike && $owner->name instanceof Node\Identifier) {
                    $this->declarations['new:' . $owner->name->toLowerString()][] = $node;
                }
            }
        }
    }
}
