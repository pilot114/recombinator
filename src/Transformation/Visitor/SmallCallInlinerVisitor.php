<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use Recombinator\Support\ExternalCode;
use Recombinator\Support\TypeInference;

/**
 * Встраивает однострочные функции и методы (`return expr;`) в места вызова:
 *
 *   private function isEmpty($v) { return $v === null || $v === ''; }
 *   ... if ($this->isEmpty($x)) ...           →  ... if ($x === null || $x === '') ...
 *   function Identifier_quoted(string $v) { return new Identifier($v, true); }
 *   Identifier_quoted($name)                  →  new Identifier($name, true)
 *
 * Цели:
 * - функция программы, объявленная один раз на верхнем уровне (не внутри
 *   `if (!function_exists(...))`), без `$this` и вызова самой себя;
 * - `$this->m()`, `static::m()`, `self::m()` в коде класса: метод ищется вверх по
 *   предкам, как это делает PHP. Для `$this->` и `static::` ни один наследник класса
 *   (включая анонимные классы и файлы проекта вне программы) не переопределяет `m`;
 *   private-метод не переопределяется вовсе. Тело метода предка D: `self`/`parent`
 *   заменяются именами из D, обращения к private-членам D запрещены (наследник их не видит).
 * Сам метод или функция остаются; неиспользуемые удалят RemoveUnused*Visitor.
 *
 * Условия на тело и аргументы:
 * - в expr нет замыканий, присваиваний, магических констант (__FUNCTION__, __METHOD__,
 *   __LINE__), func_get_args(), yield, локальных переменных кроме параметров;
 * - у параметров нет by-ref и variadic. Итоговый файл не строгий (strict_types снят),
 *   поэтому скалярный тип параметра (int, string, bool) допускается, только если тип
 *   аргумента заведомо тот же: литерал или параметр вызывающего с тем же типом,
 *   который не переприсваивается. Скалярный тип результата — если тип expr очевиден
 *   (`.` — string, сравнение — bool, метод своего класса или встроенная функция с тем
 *   же объявленным типом). float и объединения скаляров — нет (int → float);
 * - аргументы позиционные, без распаковки; пропущенный берётся из константного значения
 *   по умолчанию;
 * - порядок и число вычислений аргументов не меняются (см. orderPreserved).
 */
#[VisitorMeta('Встраивание однострочных функций и методов')]
class SmallCallInlinerVisitor extends BaseVisitor
{
    private const array SCALAR_TYPES = ['int', 'float', 'string', 'bool', 'true', 'false', 'null', 'void', 'never'];

    /** @var list<Node\Stmt\ClassLike> */
    private array $classStack = [];

    /** @var array<string, list<Node\Stmt\ClassMethod>> lowercase имя → методы программы */
    private array $methodsByName = [];

    /** @var array<string, Node\Stmt\ClassLike> lowercase имя → класс */
    private array $classes = [];

    /** @var array<string, list<Node\Stmt\Class_>> lowercase имя родителя → прямые наследники (и анонимные) */
    private array $subclasses = [];

    /** @var array<string, list<Node\Stmt\Function_>> lowercase имя → все объявления функции */
    private array $allFunctions = [];

    /** @var array<string, Node\Stmt\Function_> lowercase имя → однострочная функция */
    private array $functions = [];

    private ?ExternalCode $external = null;

    private ?TypeInference $inference = null;

    /** @var array<int, bool> id класса → наследуется ли он (или его наследник) внешним кодом */
    private array $extendedOutside = [];

    /** @var array<string, true> имена функций и методов, объявленных во внешнем коде */
    private array $externalDeclared = [];

    /** @var array<string, list<Node\Stmt\Class_>|null> */
    private array $subtypeCache = [];

    /** @var array<string, string|null> id функции:переменная → выведенный класс */
    private array $localTypes = [];

    /** @var array<int, bool> id метода → годится ли тело */
    private array $inlinable = [];

    /** @param array<string, string> $fileMap файлы проекта (путь → код) */
    public function __construct(private readonly array $fileMap = [])
    {
    }

    #[\Override]
    public function beforeTraverse(array $nodes): ?array
    {
        parent::beforeTraverse($nodes);
        $this->classStack    = [];
        $this->methodsByName = [];
        $this->classes       = [];
        $this->subclasses    = [];
        $this->functions     = [];
        $this->inlinable     = [];
        $this->subtypeCache  = [];
        $this->localTypes    = [];

        $declaredFunctions = [];
        foreach ($this->findNode(Node::class) as $node) {
            if ($node instanceof Node\Stmt\ClassMethod) {
                $this->methodsByName[$node->name->toLowerString()][] = $node;
            } elseif ($node instanceof Node\Stmt\ClassLike) {
                if ($node->name instanceof Node\Identifier) {
                    $this->classes[$node->name->toLowerString()] = $node;
                }

                if ($node instanceof Node\Stmt\Class_ && $node->extends instanceof Node\Name) {
                    $this->subclasses[strtolower(ltrim($node->extends->toString(), '\\'))][] = $node;
                }
            } elseif ($node instanceof Node\Stmt\Function_) {
                $lower                       = $node->name->toLowerString();
                $declaredFunctions[$lower][] = $node;
            }
        }

        $this->allFunctions = $declaredFunctions;
        foreach ($declaredFunctions as $lower => $declarations) {
            $function = $declarations[0];
            $parent   = $function->getAttribute('parent');
            $topLevel = $parent === null || $parent instanceof Node\Stmt\Namespace_;
            if (count($declarations) === 1 && $topLevel && $this->bodyInlinable($function, false)) {
                $this->functions[$lower] = $function;
            }
        }

        $this->inference        = new TypeInference($this->classes, $nodes);
        $this->external         = $this->fileMap === [] ? null : ExternalCode::forProgram($this->fileMap, $this->classes, $this->ast);
        $this->externalDeclared = $this->external?->declaredFunctions() ?? [];
        $this->extendedOutside  = [];

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

        if (!$node instanceof Node\Expr\MethodCall && !$node instanceof Node\Expr\StaticCall && !$node instanceof Node\Expr\FuncCall) {
            return parent::leaveNode($node);
        }

        $caller = $node->getAttribute('parent');
        while ($caller instanceof Node && !$caller instanceof Node\FunctionLike) {
            $caller = $caller->getAttribute('parent');
        }

        $caller   = $caller instanceof Node\FunctionLike ? $caller : null;
        $receiver = null;
        [$callee, $declaring] = $this->target($node) ?? [null, null];
        if (!$callee instanceof Node\FunctionLike && $node instanceof Node\Expr\MethodCall) {
            [$callee, $declaring] = $this->typedTarget($node, $caller) ?? [null, null];
            $receiver             = $node->var;
        }

        if (!$callee instanceof Node\FunctionLike) {
            return parent::leaveNode($node);
        }

        $inlined = $this->inline($callee, $node->args, $caller, $declaring, $receiver);
        if ($inlined instanceof Node\Expr) {
            // Клон тела несёт parent исходного метода — связываем заново с местом вставки
            new NodeTraverser(new \PhpParser\NodeVisitor\ParentConnectingVisitor())->traverse([$inlined]);
            $inlined->setAttribute('parent', $node->getAttribute('parent'));
        }

        return $inlined ?? parent::leaveNode($node);
    }

    /**
     * Вызываемая функция или метод и класс, где метод объявлен (null для функции).
     *
     * @return array{Node\Stmt\Function_|Node\Stmt\ClassMethod, ?Node\Stmt\Class_}|null
     */
    private function target(Node\Expr\MethodCall|Node\Expr\StaticCall|Node\Expr\FuncCall $node): ?array
    {
        if ($node instanceof Node\Expr\FuncCall) {
            $function = $node->name instanceof Node\Name ? $this->functions[strtolower(ltrim($node->name->toString(), '\\'))] ?? null : null;

            return $function === null ? null : [$function, null];
        }

        $class = end($this->classStack);
        if (!$class instanceof Node\Stmt\Class_ || !$class->name instanceof Node\Identifier || !$node->name instanceof Node\Identifier) {
            return null;
        }

        $via = match (true) {
            $node instanceof Node\Expr\MethodCall => $node->var instanceof Node\Expr\Variable && $node->var->name === 'this' ? 'this' : null,
            $node->class instanceof Node\Name => in_array($node->class->toLowerString(), ['self', 'static'], true) ? $node->class->toLowerString() : null,
            default => null,
        };
        if ($via === null || $this->insideReboundClosure($node)) {
            return null;
        }

        $name = $node->name->toLowerString();
        [$method, $declaring] = $this->lookup($class, $name) ?? [null, null];
        if (!$method instanceof Node\Stmt\ClassMethod || !$declaring instanceof Node\Stmt\Class_ || $method->stmts === null) {
            return null;
        }

        // Только «родные» формы: $this-> для обычного метода, self::/static:: для статического
        if ($method->isStatic() !== ($via !== 'this')) {
            return null;
        }

        if ($method->isPrivate() && $declaring !== $class) {
            return null;
        }

        // Позднее связывание: наследник мог переопределить
        if (!$method->isPrivate() && $via !== 'self' && ($this->overridden($class, $name) || isset($this->externalDeclared[$name]) && $this->extendedOutside($class))) {
            return null;
        }

        $key = spl_object_id($method);
        $this->inlinable[$key] ??= $this->bodyInlinable($method, true);
        if (!$this->inlinable[$key] || $declaring !== $class && $this->touchesPrivate($method, $declaring)) {
            return null;
        }

        return [$method, $declaring];
    }

    /**
     * Вызов `$obj->m()` / `$this->prop->m()`, где тип получателя известен из объявления
     * (исходный тип параметра или свойства): все классы программы этого типа разрешают `m`
     * в один и тот же метод, внешний код их не наследует, а тело видит из места вызова
     * только публичные члены (или вызов — из того же класса).
     *
     * @return array{Node\Stmt\ClassMethod, Node\Stmt\Class_}|null
     */
    private function typedTarget(Node\Expr\MethodCall $node, ?Node\FunctionLike $caller): ?array
    {
        if (!$node->name instanceof Node\Identifier) {
            return null;
        }

        // Общий вывод типов (присваивания, цепочки, instanceof), затем собственный — запасной
        $types = $this->inference?->typeOf($node->var);
        if ($types === null) {
            $type  = $this->receiverType($node->var, $caller);
            $types = $type === null ? null : [$type];
        }

        if ($types === null || $types === []) {
            return null;
        }

        $name    = $node->name->toLowerString();
        $method  = null;
        $owner   = null;
        $classes = [];
        foreach ($types as $type) {
            $found = $this->subtypes($type);
            if ($found === null) {
                return null;
            }

            array_push($classes, ...$found);
        }

        foreach ($classes as $class) {
            if ($class->isAbstract()) {
                continue; // объекта такого класса нет — только наследников
            }

            [$found, $declaring] = $this->lookup($class, $name) ?? [null, null];
            if (!$found instanceof Node\Stmt\ClassMethod || $method instanceof \PhpParser\Node\Stmt\ClassMethod && $found !== $method) {
                return null;
            }

            [$method, $owner] = [$found, $declaring];
        }

        if (!$method instanceof Node\Stmt\ClassMethod || !$owner instanceof Node\Stmt\Class_ || $method->isStatic() || $method->stmts === null) {
            return null;
        }

        $key = spl_object_id($method);
        $this->inlinable[$key] ??= $this->bodyInlinable($method, true);
        if (!$this->inlinable[$key] || $this->usesLateBinding($method)) {
            return null;
        }

        $current = end($this->classStack);
        $inside  = $current instanceof Node\Stmt\Class_ && $current === $owner;
        $writes  = $this->findNode(Node\Expr\Assign::class, $method->stmts ?? []) !== [] || $this->findNode(Node\Expr\AssignOp::class, $method->stmts ?? []) !== [];
        if (!$inside && (!$method->isPublic() || $writes || !$this->onlyPublicMembers($method, $owner))) {
            return null;
        }

        return [$method, $owner];
    }

    /** Lowercase имя класса или интерфейса программы — тип получателя по объявлениям. */
    private function receiverType(Node\Expr $receiver, ?Node\FunctionLike $caller, int $depth = 0): ?string
    {
        if ($depth > 6) {
            return null;
        }

        $next = $depth + 1;

        return match (true) {
            $receiver instanceof Node\Expr\Variable && is_string($receiver->name) && $receiver->name !== 'this'
                => $this->variableType($receiver->name, $caller, $next),
            $receiver instanceof Node\Expr\PropertyFetch && $receiver->var instanceof Node\Expr\Variable && $receiver->var->name === 'this'
                && $receiver->name instanceof Node\Identifier && !$this->insideReboundClosure($receiver)
                => $this->typeName($this->propertyType($receiver->name->toString())),
            $receiver instanceof Node\Expr\New_ && $receiver->class instanceof Node\Name => strtolower(ltrim($receiver->class->toString(), '\\')),
            $receiver instanceof Node\Expr\Clone_ => $this->receiverType($receiver->expr, $caller, $next),
            $receiver instanceof Node\Expr\MethodCall && $receiver->name instanceof Node\Identifier => $this->resultType($receiver, $caller, $next),
            $receiver instanceof Node\Expr\StaticCall && $receiver->class instanceof Node\Name && $receiver->name instanceof Node\Identifier
                => $this->staticResultType($receiver),
            default => null,
        };
    }

    private function typeName(?Node $type): ?string
    {
        if ($type instanceof Node\NullableType) {
            $type = $type->type;
        }

        return $type instanceof Node\Name && !in_array($type->toLowerString(), ['self', 'static', 'parent'], true)
            ? strtolower(ltrim($type->toString(), '\\'))
            : null;
    }

    /** Параметр (не переприсваиваемый) или локальная переменная, все присваивания которой — один класс. */
    private function variableType(string $name, ?Node\FunctionLike $function, int $depth): ?string
    {
        if (!$function instanceof Node\FunctionLike) {
            return null;
        }

        foreach ($function->getParams() as $param) {
            if ($param->var instanceof Node\Expr\Variable && $param->var->name === $name) {
                return !$param->byRef && !$param->variadic && !$this->mayChange($function, $name, true)
                    ? $this->typeName($param->getAttribute('originalType') ?? $param->type)
                    : null;
            }
        }

        if ($function instanceof Node\Expr\Closure && array_any($function->uses, static fn(Node\ClosureUse $u): bool => $u->var->name === $name)) {
            return null;
        }

        $key = spl_object_id($function) . ':' . $name;
        if (array_key_exists($key, $this->localTypes)) {
            return $this->localTypes[$key];
        }

        $this->localTypes[$key] = null;
        $type                   = null;
        foreach ($this->findNode(Node::class, $function->getStmts() ?? []) as $node) {
            if ($node instanceof Node\Expr\Assign && $node->var instanceof Node\Expr\Variable && $node->var->name === $name) {
                if ($node->expr instanceof Node\Expr\ConstFetch && $node->expr->name->toLowerString() === 'null') {
                    continue; // вызов метода на null — ошибка; в корректной программе здесь объект
                }

                $assigned = $this->receiverType($node->expr, $this->enclosingFunction($node), $depth);
                if ($assigned === null || $type !== null && $assigned !== $type) {
                    return null;
                }

                $type = $assigned;
                continue;
            }

            $binds = match (true) {
                $node instanceof Node\Stmt\Foreach_ => [$node->keyVar, $node->valueVar],
                $node instanceof Node\Expr\AssignRef, $node instanceof Node\Expr\AssignOp => [$node->var],
                $node instanceof Node\Expr\List_ => array_map(static fn(?Node\ArrayItem $i): ?Node => $i?->value, $node->items),
                $node instanceof Node\Stmt\Global_ => $node->vars,
                $node instanceof Node\Stmt\Static_ => array_map(static fn(Node\StaticVar $v): Node => $v->var, $node->vars),
                $node instanceof Node\Stmt\Catch_ => [$node->var],
                $node instanceof Node\Expr\ClosureUse => $node->byRef ? [$node->var] : [],
                $node instanceof Node\Arg => $this->mayBeByRef($node, true) ? [$node->value] : [],
                default => [],
            };
            if (array_any($binds, static fn(?Node $v): bool => $v instanceof Node\Expr\Variable && $v->name === $name)) {
                return null;
            }

            if ($node instanceof Node\Expr\Variable && !is_string($node->name)) {
                return null; // $$x
            }
        }

        return $this->localTypes[$key] = $type;
    }

    private function enclosingFunction(Node $node): ?Node\FunctionLike
    {
        for ($current = $node->getAttribute('parent'); $current instanceof Node; $current = $current->getAttribute('parent')) {
            if ($current instanceof Node\FunctionLike) {
                return $current;
            }
        }

        return null;
    }

    /** Объявленный тип результата метода, одинаковый у всех классов типа получателя. */
    private function resultType(Node\Expr\MethodCall $call, ?Node\FunctionLike $caller, int $depth): ?string
    {
        $receiver = $call->var instanceof Node\Expr\Variable && $call->var->name === 'this'
            ? (end($this->classStack) instanceof Node\Stmt\Class_ && end($this->classStack)->name instanceof \PhpParser\Node\Identifier ? end($this->classStack)->name->toLowerString() : null)
            : $this->receiverType($call->var, $caller, $depth);
        $classes  = $receiver === null ? null : $this->subtypes($receiver);
        if ($classes === null || !$call->name instanceof Node\Identifier) {
            return null;
        }

        $result = null;
        foreach ($classes as $class) {
            if ($class->isAbstract()) {
                continue;
            }

            [$method, $declaring] = $this->lookup($class, $call->name->toLowerString()) ?? [null, null];
            if (!$method instanceof Node\Stmt\ClassMethod || !$declaring instanceof Node\Stmt\Class_) {
                return null;
            }

            $declared = $method->getAttribute('originalReturnType') ?? $method->returnType;
            $bare     = $declared instanceof Node\NullableType ? $declared->type : $declared;
            $type     = match (true) {
                $bare instanceof Node\Name && $bare->toLowerString() === 'static' => $receiver,
                $bare instanceof Node\Name && $bare->toLowerString() === 'self' && $declaring->name instanceof \PhpParser\Node\Identifier => $declaring->name->toLowerString(),
                default => $this->typeName($declared),
            };
            if ($type === null || $result !== null && $result !== $type) {
                return null;
            }

            $result = $type;
        }

        return $result;
    }

    private function staticResultType(Node\Expr\StaticCall $call): ?string
    {
        $class = $call->class instanceof Node\Name ? $this->classes[strtolower(ltrim($call->class->toString(), '\\'))] ?? null : null;
        if (!$class instanceof Node\Stmt\Class_ || !$call->name instanceof Node\Identifier) {
            return null;
        }

        [$method, $declaring] = $this->lookup($class, $call->name->toLowerString()) ?? [null, null];
        if (!$method instanceof Node\Stmt\ClassMethod || !$method->isStatic()) {
            return null;
        }

        $declared = $method->getAttribute('originalReturnType') ?? $method->returnType;
        $bare     = $declared instanceof Node\NullableType ? $declared->type : $declared;

        // static:: у класса без наследников — сам класс
        return $bare instanceof Node\Name && in_array($bare->toLowerString(), ['self', 'static'], true)
            ? ($bare->toLowerString() === 'self' || !isset($this->subclasses[$class->name?->toLowerString() ?? '']) ? $declaring?->name?->toLowerString() : null)
            : $this->typeName($declared);
    }

    /** Объявленный (исходный) тип свойства текущего класса или его предков. */
    private function propertyType(string $name): ?Node
    {
        $seen = [];
        for ($class = end($this->classStack); $class instanceof Node\Stmt\Class_;) {
            $property = $class->getProperty($name);
            if ($property instanceof Node\Stmt\Property) {
                return $property->getAttribute('originalType') ?? $property->type;
            }

            foreach ($class->getMethod('__construct')->params ?? [] as $param) {
                if ($param->flags !== 0 && $param->var instanceof Node\Expr\Variable && $param->var->name === $name) {
                    return $param->getAttribute('originalType') ?? $param->type;
                }
            }

            $parent = $class->extends instanceof Node\Name ? strtolower(ltrim($class->extends->toString(), '\\')) : null;
            if ($parent === null || isset($seen[$parent])) {
                return null;
            }

            $seen[$parent] = true;
            $class         = $this->classes[$parent] ?? null;
        }

        return null;
    }

    /**
     * Все классы программы, чьи объекты имеют тип `$type` (сам класс и наследники,
     * реализации интерфейса). null — тип не из программы, или его наследует анонимный
     * класс или внешний код.
     *
     * @return list<Node\Stmt\Class_>|null
     */
    private function subtypes(string $type): ?array
    {
        if (array_key_exists($type, $this->subtypeCache)) {
            return $this->subtypeCache[$type];
        }

        if (!isset($this->classes[$type]) || $this->external?->extendsOrImplements((string) $this->classes[$type]->name) === true) {
            return $this->subtypeCache[$type] = null;
        }

        $result = [];
        foreach ($this->findNode(Node\Stmt\Class_::class) as $class) {
            if (!$this->isSubtype($class, $type)) {
                continue;
            }

            // Анонимный и внешний наследник не видны; класс с атрибутами (сущность Doctrine)
            // может получить ленивый прокси — наследника, сгенерированного во время выполнения
            if (!$class->name instanceof Node\Identifier || $class->attrGroups !== [] || $this->external?->extendsOrImplements($class->name->toString()) === true) {
                return $this->subtypeCache[$type] = null;
            }

            $result[] = $class;
        }

        return $this->subtypeCache[$type] = $result;
    }

    private function isSubtype(Node\Stmt\ClassLike $class, string $type, int $depth = 0): bool
    {
        if ($depth > 32) {
            return true;
        }

        if ($class->name instanceof Node\Identifier && $class->name->toLowerString() === $type) {
            return true;
        }

        $parents = match (true) {
            $class instanceof Node\Stmt\Class_ => [$class->extends, ...$class->implements],
            $class instanceof Node\Stmt\Interface_ => $class->extends,
            default => [],
        };
        foreach ($parents as $parent) {
            if (!$parent instanceof Node\Name) {
                continue;
            }

            $lower = strtolower(ltrim($parent->toString(), '\\'));
            if ($lower === $type || isset($this->classes[$lower]) && $this->isSubtype($this->classes[$lower], $type, $depth + 1)) {
                return true;
            }
        }

        return false;
    }

    /** `static` в теле — класс объекта; на месте вызова его не выразить именем. */
    private function usesLateBinding(Node\Stmt\ClassMethod $method): bool
    {
        return array_any($this->findNode(Node\Name::class, $method->stmts ?? []), static fn(Node\Name $n): bool => $n->toLowerString() === 'static');
    }

    /** Все члены, к которым тело обращается через $this, self, parent, — публичные. */
    private function onlyPublicMembers(Node\Stmt\ClassMethod $method, Node\Stmt\Class_ $owner): bool
    {
        foreach ($this->findNode(Node::class, $method->stmts ?? []) as $node) {
            $onThis = ($node instanceof Node\Expr\PropertyFetch || $node instanceof Node\Expr\MethodCall
                    || $node instanceof Node\Expr\NullsafePropertyFetch || $node instanceof Node\Expr\NullsafeMethodCall)
                && $node->var instanceof Node\Expr\Variable && $node->var->name === 'this';
            $onClass = ($node instanceof Node\Expr\StaticCall || $node instanceof Node\Expr\StaticPropertyFetch || $node instanceof Node\Expr\ClassConstFetch)
                && $node->class instanceof Node\Name && in_array($node->class->toLowerString(), ['self', 'parent'], true);
            if (!$onThis && !$onClass) {
                continue;
            }

            if (!$node instanceof Node\Expr || !$node->name instanceof Node\Identifier || !$this->isPublicMember($owner, $node)) {
                return false;
            }
        }

        return true;
    }

    private function isPublicMember(Node\Stmt\Class_ $owner, Node\Expr $access): bool
    {
        $name = $access->name instanceof Node\Identifier ? $access->name->toString() : '';
        $seen = [];
        for ($class = $owner; $class instanceof Node\Stmt\Class_;) {
            $visible = match (true) {
                $access instanceof Node\Expr\MethodCall, $access instanceof Node\Expr\NullsafeMethodCall, $access instanceof Node\Expr\StaticCall
                    => $class->getMethod($name)?->isPublic(),
                $access instanceof Node\Expr\ClassConstFetch => $this->constantVisibility($class, $name),
                default => $this->propertyVisibility($class, $name),
            };
            if ($visible !== null) {
                return $visible;
            }

            $parent = $class->extends instanceof Node\Name ? strtolower(ltrim($class->extends->toString(), '\\')) : null;
            if ($parent === null || isset($seen[$parent])) {
                return false;
            }

            $seen[$parent] = true;
            $class         = $this->classes[$parent] ?? null;
        }

        return false;
    }

    private function constantVisibility(Node\Stmt\Class_ $class, string $name): ?bool
    {
        if (strtolower($name) === 'class') {
            return true;
        }

        foreach ($class->getConstants() as $const) {
            foreach ($const->consts as $item) {
                if ($item->name->toString() === $name) {
                    return $const->isPublic();
                }
            }
        }

        return null;
    }

    private function propertyVisibility(Node\Stmt\Class_ $class, string $name): ?bool
    {
        $property = $class->getProperty($name);
        if ($property instanceof Node\Stmt\Property) {
            return $property->isPublic();
        }

        foreach ($class->getMethod('__construct')->params ?? [] as $param) {
            if ($param->flags !== 0 && $param->var instanceof Node\Expr\Variable && $param->var->name === $name) {
                return ($param->flags & \PhpParser\Modifiers::PUBLIC) !== 0
                    || ($param->flags & (\PhpParser\Modifiers::PRIVATE | \PhpParser\Modifiers::PROTECTED)) === 0;
            }
        }

        return null;
    }

    /** Внутри замыкания, которое привязывают к другому объекту (Closure::bind, bindTo, call). */
    private function insideReboundClosure(Node $node): bool
    {
        for ($current = $node->getAttribute('parent'); $current instanceof Node; $current = $current->getAttribute('parent')) {
            if ($current instanceof Node\Stmt\ClassLike) {
                return false;
            }

            if (!$current instanceof Node\Expr\Closure && !$current instanceof Node\Expr\ArrowFunction) {
                continue;
            }

            $arg  = $current->getAttribute('parent');
            $call = $arg instanceof Node\Arg ? $arg->getAttribute('parent') : $arg;
            $name = $call instanceof Node\Expr\StaticCall || $call instanceof Node\Expr\MethodCall ? $call->name : null;
            if ($name instanceof Node\Identifier && in_array($name->toLowerString(), ['bind', 'bindto', 'call'], true)) {
                return true;
            }

            // ($fn)->bindTo(...) / $fn = function () {...}; $fn->call($obj) — переменную не отслеживаем
            if (!$arg instanceof Node\Arg) {
                return true;
            }
        }

        return false;
    }

    /**
     * Метод по имени: в классе или ближайшем предке программы.
     *
     * @return array{Node\Stmt\ClassMethod, Node\Stmt\Class_}|null
     */
    private function lookup(Node\Stmt\Class_ $class, string $name): ?array
    {
        $seen = [];
        for ($current = $class; $current instanceof Node\Stmt\Class_;) {
            $method = $current->getMethod($name);
            if ($method instanceof Node\Stmt\ClassMethod) {
                return [$method, $current];
            }

            if (!$current->extends instanceof Node\Name) {
                return null;
            }

            $parent = strtolower(ltrim($current->extends->toString(), '\\'));
            if (isset($seen[$parent])) {
                return null;
            }

            $seen[$parent] = true;
            $current       = $this->classes[$parent] ?? null;
        }

        return null; // предок вне программы
    }

    /** Наследует ли класс (или его наследника) код вне программы — тогда он может переопределить метод. */
    private function extendedOutside(Node\Stmt\Class_ $class): bool
    {
        $key = spl_object_id($class);
        if (isset($this->extendedOutside[$key])) {
            return $this->extendedOutside[$key];
        }

        $result = false;
        $queue  = [$class];
        $seen   = [];
        while ($queue !== [] && !$result) {
            $current = array_pop($queue);
            if (!$current->name instanceof Node\Identifier || isset($seen[$current->name->toLowerString()])) {
                continue;
            }

            $seen[$current->name->toLowerString()] = true;
            $result = $this->external?->extendsOrImplements($current->name->toString()) ?? false;
            array_push($queue, ...$this->subclasses[$current->name->toLowerString()] ?? []);
        }

        return $this->extendedOutside[$key] = $result;
    }

    /** Переопределяет ли метод какой-либо наследник класса (в том числе анонимный). */
    private function overridden(Node\Stmt\Class_ $class, string $name): bool
    {
        $queue = [$class];
        $seen  = [];
        while ($queue !== []) {
            $current = array_pop($queue);
            $lower   = $current->name instanceof Node\Identifier ? $current->name->toLowerString() : null;
            if ($lower === null || isset($seen[$lower])) {
                continue;
            }

            $seen[$lower] = true;
            foreach ($this->subclasses[$lower] ?? [] as $child) {
                if ($child->getMethod($name) instanceof Node\Stmt\ClassMethod || !$child->name instanceof Node\Identifier) {
                    return true; // анонимный наследник разбирать не будем
                }

                $queue[] = $child;
            }
        }

        return false;
    }

    /** Обращается ли тело к private-членам класса (наследник их не видит). */
    private function touchesPrivate(Node\Stmt\ClassMethod $method, Node\Stmt\Class_ $declaring): bool
    {
        $private = [];
        foreach ($declaring->stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\Property && $stmt->isPrivate()) {
                foreach ($stmt->props as $prop) {
                    $private[$prop->name->toLowerString()] = true;
                }
            } elseif ($stmt instanceof Node\Stmt\ClassMethod && $stmt->isPrivate()) {
                $private[$stmt->name->toLowerString()] = true;
            } elseif ($stmt instanceof Node\Stmt\ClassConst && ($stmt->flags & \PhpParser\Modifiers::PRIVATE) !== 0) {
                foreach ($stmt->consts as $const) {
                    $private[$const->name->toLowerString()] = true;
                }
            }
        }

        foreach ($declaring->getMethod('__construct')->params ?? [] as $param) {
            if (($param->flags & \PhpParser\Modifiers::PRIVATE) !== 0 && $param->var instanceof Node\Expr\Variable && is_string($param->var->name)) {
                $private[strtolower($param->var->name)] = true;
            }
        }

        if ($private === []) {
            return false;
        }

        foreach ($this->findNode(Node::class, $method->stmts ?? []) as $node) {
            $name = match (true) {
                $node instanceof Node\Expr\PropertyFetch, $node instanceof Node\Expr\NullsafePropertyFetch, $node instanceof Node\Expr\MethodCall,
                $node instanceof Node\Expr\NullsafeMethodCall, $node instanceof Node\Expr\StaticCall, $node instanceof Node\Expr\StaticPropertyFetch,
                $node instanceof Node\Expr\ClassConstFetch => $node->name,
                default => null,
            };
            if ($name instanceof Node\Identifier) {
                if (isset($private[$name->toLowerString()])) {
                    return true;
                }
            } elseif ($name instanceof \PhpParser\Node) {
                return true; // динамическое имя
            }
        }

        return false;
    }

    /** @param array<Node\Arg|Node\VariadicPlaceholder> $args */
    private function inline(
        Node\Stmt\Function_|Node\Stmt\ClassMethod $method,
        array $args,
        ?Node\FunctionLike $caller = null,
        ?Node\Stmt\Class_ $declaring = null,
        ?Node\Expr $receiver = null,
    ): ?Node\Expr {
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
            if ($scalar !== null && !$this->hasType($arg->value, $scalar, $caller) && !$this->coercedLater($return->expr, $param->var->name, $scalar)) {
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

        // Вызов на объекте: $this тела — получатель (читается столько раз, сколько $this в теле)
        if ($receiver instanceof Node\Expr) {
            $thisUses = count(array_filter($this->findNode(Node\Expr\Variable::class, [$return->expr]), static fn(Node\Expr\Variable $v): bool => $v->name === 'this'));
            $stable   = $receiver instanceof Node\Expr\Variable && is_string($receiver->name) && $this->unaliased($receiver->name, $caller);
            $effects  = $this->findNode(Node\Expr\CallLike::class, [$return->expr]) !== []
                || $this->findNode(Node\Expr\Assign::class, [$return->expr]) !== [] || $this->findNode(Node\Expr\AssignOp::class, [$return->expr]) !== [];
            if (!$stable && ($thisUses > 1 || $impure !== [] || $effects)) {
                return null;
            }

            $values["\0this"] = $receiver;
        }

        // Тело метода предка: self/parent — относительно класса, где метод объявлен
        $names = [];
        if ($declaring instanceof Node\Stmt\Class_ && $declaring->name instanceof Node\Identifier) {
            $names['self'] = $declaring->name->toString();
            if ($declaring->extends instanceof Node\Name) {
                $names['parent'] = ltrim($declaring->extends->toString(), '\\');
            }
        }

        $body = $this->deepClone($return->expr);
        assert($body instanceof Node\Expr);
        $result = new NodeTraverser(new class ($values, $names, $this) extends NodeVisitorAbstract {
            /**
             * @param array<string, Node\Expr> $values
             * @param array<string, string>    $names
             */
            public function __construct(private readonly array $values, private readonly array $names, private readonly SmallCallInlinerVisitor $owner)
            {
            }

            public function leaveNode(Node $node): ?Node
            {
                if ($node instanceof Node\Expr\Variable && is_string($node->name) && isset($this->values[$node->name])) {
                    return $this->owner->cloneNode($this->values[$node->name]);
                }

                if ($node instanceof Node\Expr\Variable && $node->name === 'this' && isset($this->values["\0this"])) {
                    return $this->owner->cloneNode($this->values["\0this"]);
                }

                if ($node instanceof Node\Name && isset($this->names[$node->toLowerString()])) {
                    return new Node\Name\FullyQualified($this->names[$node->toLowerString()], $node->getAttributes());
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

    /** Тело — одна инструкция `return expr;`, которую можно перенести в место вызова. */
    private function bodyInlinable(Node\Stmt\Function_|Node\Stmt\ClassMethod $method, bool $allowThis): bool
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

        $self       = $method->name->toLowerString();
        $isFunction = $method instanceof Node\Stmt\Function_;
        foreach ($this->findNode(Node::class, [$stmts[0]->expr]) as $node) {
            $forbidden = match (true) {
                $node instanceof Node\Expr\Closure, $node instanceof Node\Expr\ArrowFunction, $node instanceof Node\Expr\Yield_,
                $node instanceof Node\Expr\YieldFrom, $node instanceof Node\Scalar\MagicConst, $node instanceof Node\Expr\Include_,
                $node instanceof Node\Expr\Eval_, $node instanceof Node\Expr\AssignRef => true,
                // Присваивание свойству вычисляется в месте вызова так же; локальной переменной — нет
                $node instanceof Node\Expr\Assign, $node instanceof Node\Expr\AssignOp => !$node->var instanceof Node\Expr\PropertyFetch
                    && !$node->var instanceof Node\Expr\StaticPropertyFetch,
                $node instanceof Node\Expr\FuncCall => !$node->name instanceof Node\Name
                    || in_array($node->name->toLowerString(), ['func_get_args', 'func_get_arg', 'func_num_args', 'compact', 'extract', 'get_defined_vars'], true)
                    || $isFunction && strtolower(ltrim($node->name->toString(), '\\')) === $self,
                ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\StaticCall) => !$node->name instanceof Node\Identifier
                    || !$isFunction && $node->name->toLowerString() === $self,
                // Локальная переменная, не параметр (и не $this): значение неизвестно
                $node instanceof Node\Expr\Variable => !is_string($node->name)
                    || ($node->name === 'this' ? !$allowThis : !isset($names[$node->name])),
                // parent:: в теле функции не встречается; в методе без родителя — ошибка, не трогаем
                $node instanceof Node\Name && $node->toLowerString() === 'parent' => $method instanceof Node\Stmt\ClassMethod
                    && (!$method->getAttribute('parent') instanceof Node\Stmt\Class_ || !$method->getAttribute('parent')->extends instanceof Node\Name),
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

    /**
     * Приведение параметра произойдёт и после подстановки: каждое его вхождение — прямой
     * аргумент функции, метода или конструктора с тем же скалярным типом в этой позиции
     * (для string — ещё операнд `.` и часть интерполяции: для значений, которые параметр
     * принял бы без TypeError, результат тот же).
     */
    private function coercedLater(Node\Expr $expr, string $name, string $type): bool
    {
        $uses = array_filter($this->findNode(Node\Expr\Variable::class, [$expr]), static fn(Node\Expr\Variable $v): bool => $v->name === $name);
        foreach ($uses as $use) {
            $parent = $use->getAttribute('parent');
            if ($type === 'string' && ($parent instanceof Node\Expr\BinaryOp\Concat || $parent instanceof Node\Scalar\InterpolatedString)) {
                continue;
            }

            $call = $parent instanceof Node\Arg && !$parent->unpack && !$parent->name instanceof Node\Identifier ? $parent->getAttribute('parent') : null;
            if (!$call instanceof Node\Expr\CallLike) {
                return false;
            }

            $position = array_search($parent, $call->getRawArgs(), true);
            if (!is_int($position) || $this->calleeParamTypes($call, $position) !== [$type]) {
                return false;
            }
        }

        return true;
    }

    /**
     * Объявленные скалярные типы параметра в позиции у всех возможных целей вызова
     * (пустой список — цель неизвестна).
     *
     * @return list<string>
     */
    private function calleeParamTypes(Node\Expr\CallLike $call, int $position): array
    {
        $targets = match (true) {
            $call instanceof Node\Expr\New_ && $call->class instanceof Node\Name => array_filter([$this->constructorOf(strtolower(ltrim($call->class->toString(), '\\')))]),
            $call instanceof Node\Expr\FuncCall && $call->name instanceof Node\Name => $this->functionTargets($call->name),
            // Метод — только на $this или по имени класса: у объекта неизвестного класса метод может быть внешним
            ($call instanceof Node\Expr\MethodCall && $call->var instanceof Node\Expr\Variable && $call->var->name === 'this'
                || $call instanceof Node\Expr\StaticCall && $call->class instanceof Node\Name)
                && $call->name instanceof Node\Identifier => $this->methodsByName[$call->name->toLowerString()] ?? [],
            default => [],
        };

        $types = [];
        foreach ($targets as $target) {
            if ($target instanceof \ReflectionFunction) {
                $parameter = $target->getParameters()[$position] ?? null;
                $type      = $parameter?->getType();
                $types[]   = $type instanceof \ReflectionNamedType && !$parameter->isVariadic() ? ($type->allowsNull() ? '?' : '') . $type->getName() : 'unknown';
                continue;
            }

            $param   = $target->params[$position] ?? null;
            $types[] = $param instanceof Node\Param && !$param->variadic && !$param->byRef ? $this->scalarOf($param->type) ?? 'none' : 'unknown';
        }

        return array_values(array_unique($types));
    }

    private function constructorOf(string $class): ?Node\Stmt\ClassMethod
    {
        $seen = [];
        for ($current = $this->classes[$class] ?? null; $current instanceof Node\Stmt\Class_;) {
            $ctor = $current->getMethod('__construct');
            if ($ctor instanceof Node\Stmt\ClassMethod) {
                return $ctor;
            }

            $parent = $current->extends instanceof Node\Name ? strtolower(ltrim($current->extends->toString(), '\\')) : null;
            if ($parent === null || isset($seen[$parent])) {
                return null;
            }

            $seen[$parent] = true;
            $current       = $this->classes[$parent] ?? null;
        }

        return null;
    }

    /** @return list<Node\Stmt\Function_|\ReflectionFunction> */
    private function functionTargets(Node\Name $name): array
    {
        $lower = strtolower(ltrim($name->toString(), '\\'));

        return $this->allFunctions[$lower] ?? (function_exists($lower) && new \ReflectionFunction($lower)->isInternal() ? [new \ReflectionFunction($lower)] : []);
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
    /** $loose: вызов неизвестного (внешнего) метода считается передачей по значению — для вывода типа объекта */
    private function mayChange(Node\FunctionLike $function, string $name, bool $loose = false): bool
    {
        foreach ($this->findNode(Node::class, $function->getStmts() ?? []) as $node) {
            $target = match (true) {
                $node instanceof Node\Expr\Assign, $node instanceof Node\Expr\AssignOp, $node instanceof Node\Expr\AssignRef,
                $node instanceof Node\Expr\PreInc, $node instanceof Node\Expr\PreDec, $node instanceof Node\Expr\PostInc,
                $node instanceof Node\Expr\PostDec => $node->var,
                $node instanceof Node\Stmt\Foreach_ => $node->valueVar,
                $node instanceof Node\Expr\ClosureUse => $node->byRef ? $node->var : null,
                // Аргумент вызова может быть по ссылке; встроенные функции проверяем рефлексией
                $node instanceof Node\Arg => $this->mayBeByRef($node, $loose) ? $node->value : null,
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

    private function mayBeByRef(Node\Arg $arg, bool $loose = false): bool
    {
        $call     = $arg->getAttribute('parent');
        $position = $call instanceof Node\Expr\CallLike ? array_search($arg, $call->getRawArgs(), true) : false;
        if (!is_int($position) || $arg->name instanceof Node\Identifier || $arg->unpack) {
            return true;
        }

        // Метод программы: по ссылке, если так объявлен хоть один одноимённый метод
        if (($call instanceof Node\Expr\MethodCall || $call instanceof Node\Expr\StaticCall) && $call->name instanceof Node\Identifier) {
            $methods = $this->methodsByName[$call->name->toLowerString()] ?? [];

            return $methods === [] ? !$loose : array_any($methods, static function (Node\Stmt\ClassMethod $m) use ($position): bool {
                $param = $m->params[$position] ?? end($m->params);

                return $param instanceof Node\Param && $param->byRef;
            });
        }

        if ($loose && $call instanceof Node\Expr\New_) {
            $ctor  = $call->class instanceof Node\Name ? $this->constructorOf(strtolower(ltrim($call->class->toString(), '\\'))) : null;
            $param = $ctor?->params[$position] ?? null;

            return $param instanceof Node\Param && $param->byRef;
        }

        if ($loose && $call instanceof Node\Expr\FuncCall && $call->name instanceof Node\Name && isset($this->allFunctions[strtolower(ltrim($call->name->toString(), '\\'))])) {
            return array_any($this->allFunctions[strtolower(ltrim($call->name->toString(), '\\'))], static fn(Node\Stmt\Function_ $f): bool => ($f->params[$position] ?? null)?->byRef === true);
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
    private function orderPreserved(Node\Stmt\Function_|Node\Stmt\ClassMethod $method, Node\Expr $expr, array $values, array $impure, ?Node\FunctionLike $caller = null): bool
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
            || $this->findNode(Node\Expr\Assign::class, [$expr]) !== [] || $this->findNode(Node\Expr\AssignOp::class, [$expr]) !== []
            || $this->findNode(Node\Expr\PropertyFetch::class, [$expr]) !== [];
    }
}
