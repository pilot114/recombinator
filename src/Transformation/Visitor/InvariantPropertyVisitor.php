<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;
use Recombinator\Core\TargetEnvironment;
use Recombinator\Support\TypeInference;

/**
 * Подставляет значение свойства, одинаковое у всех объектов класса (инвариант из
 * описания сервера — например, результат DoctrineMetadataProbe):
 *
 *   $class->associationMappings   →  []
 *   $this->inheritanceType        →  1
 *
 * Получатель — объект этого класса или его наследника по выводу типов (TypeInference);
 * подставляется только чтение — не запись, не ссылка, не unset/isset и не цель
 * `$obj->prop[...] = …`. Допущение: свойство равно значению инварианта всё время жизни
 * объекта (для метаданных Doctrine — они не меняются после загрузки, а инвариант —
 * значение по умолчанию, которое загрузка не трогала).
 */
#[VisitorMeta('Подстановка инвариантов свойств (метаданные Doctrine и др.)', targets: [Node\Stmt\ClassLike::class])]
class InvariantPropertyVisitor extends BaseVisitor
{
    private ?TypeInference $inference = null;

    /** @var array<string, list<Node\Stmt\ClassMethod>>|null */
    private ?array $methodsByName = null;

    /** @var array<string, Node\Stmt\ClassLike> */
    private array $classes = [];

    public function __construct(private readonly TargetEnvironment $environment)
    {
    }

    #[\Override]
    public function beforeTraverse(array $nodes): ?array
    {
        parent::beforeTraverse($nodes);
        $this->inference     = null;
        $this->methodsByName = null;
        if ($this->environment->invariants === []) {
            return null;
        }

        $this->classes = [];
        foreach ($this->findNode(Node\Stmt\ClassLike::class) as $class) {
            if ($class->name instanceof Node\Identifier) {
                $this->classes[$class->name->toLowerString()] = $class;
            }
        }

        $this->inference = new TypeInference($this->classes, $nodes);

        return null;
    }

    public function enterNode(Node $node): ?Node
    {
        if (!$this->inference instanceof TypeInference) {
            return null;
        }

        // isset()/empty() над инвариантом: [] и null — не заданы, скаляр — задан
        if (($node instanceof Node\Expr\Isset_ && count($node->vars) === 1) || $node instanceof Node\Expr\Empty_) {
            $checked = $node instanceof Node\Expr\Isset_ ? $node->vars[0] : $node->expr;
            $set     = $this->isSet($checked);

            return $set === null ? null : new Node\Expr\ConstFetch(new Node\Name(($node instanceof Node\Expr\Isset_ ? $set : !$set) ? 'true' : 'false'));
        }

        // Результат метода, заданный инвариантом (`"isNativeLazyObjectsEnabled()": true`)
        if ($node instanceof Node\Expr\MethodCall && $node->name instanceof Node\Identifier && $this->sideEffectFree($node->var)) {
            [$value, $found] = $this->valueFor($node->var, $node->name->toString() . '()');

            return $found ? $this->literal($value) : null;
        }

        if (
            !$node instanceof Node\Expr\PropertyFetch && !$node instanceof Node\Expr\NullsafePropertyFetch
            || !$node->name instanceof Node\Identifier || $this->isWritten($node)
        ) {
            return null;
        }

        [$value, $found] = $this->valueOf($node);

        return $found ? $this->literal($value) : null;
    }

    /**
     * Вычисление получателя можно опустить: переменные, свойства и геттеры (методы
     * программы с этим именем — все вида `return $this->свойство;`).
     */
    private function sideEffectFree(Node\Expr $expr): bool
    {
        return match (true) {
            $expr instanceof Node\Expr\Variable => true,
            $expr instanceof Node\Expr\PropertyFetch => $this->sideEffectFree($expr->var),
            $expr instanceof Node\Expr\MethodCall => $expr->name instanceof Node\Identifier && $expr->args === [] && $this->sideEffectFree($expr->var)
                && $this->getterOnly($expr->name->toLowerString()),
            default => false,
        };
    }

    private function getterOnly(string $name): bool
    {
        if ($this->methodsByName === null) {
            $this->methodsByName = [];
            foreach ($this->findNode(Node\Stmt\ClassMethod::class) as $method) {
                $this->methodsByName[$method->name->toLowerString()][] = $method;
            }
        }

        $methods = $this->methodsByName[$name] ?? [];

        return $methods !== [] && array_all($methods, static fn(Node\Stmt\ClassMethod $m): bool => count($m->stmts ?? []) === 1
            && $m->stmts[0] instanceof Node\Stmt\Return_ && $m->stmts[0]->expr instanceof Node\Expr\PropertyFetch
            && $m->stmts[0]->expr->var instanceof Node\Expr\Variable && $m->stmts[0]->expr->var->name === 'this');
    }

    /**
     * Инвариант по типу получателя: у всех возможных классов один и тот же.
     *
     * @return array{mixed, bool}
     */
    private function valueFor(Node\Expr $receiver, string $name): array
    {
        $types = $this->inference?->typeOf($receiver);
        if ($types === null || $types === []) {
            return [null, false];
        }

        $value = null;
        $found = false;
        foreach ($types as $type) {
            [$typeValue, $typeFound] = $this->invariantFor($type, $name);
            if (!$typeFound || $found && $typeValue !== $value) {
                return [null, false];
            }

            [$value, $found] = [$typeValue, true];
        }

        return [$value, $found];
    }

    /**
     * Задано ли (isset) выражение над инвариантом: свойство со значением null, элемент
     * пустого массива — нет; свойство со скалярным значением — да; иначе неизвестно.
     */
    private function isSet(Node\Expr $expr): ?bool
    {
        $dims = 0;
        $base = $expr;
        while ($base instanceof Node\Expr\ArrayDimFetch) {
            if ($base->dim instanceof Node\Expr && !$this->isPure($base->dim)) {
                return null;
            }

            $dims++;
            $base = $base->var;
        }

        if (!$base instanceof Node\Expr\PropertyFetch || !$base->name instanceof Node\Identifier) {
            return null;
        }

        [$value, $found] = $this->valueOf($base);
        if (!$found) {
            return null;
        }

        return match (true) {
            $value === null => false,
            $dims > 0 && $value === [] => false,
            $dims === 0 && is_scalar($value) => true,
            default => null,
        };
    }

    private function isPure(Node\Expr $expr): bool
    {
        return $expr instanceof Node\Scalar || $expr instanceof Node\Expr\Variable || $expr instanceof Node\Expr\ConstFetch
            || $expr instanceof Node\Expr\ClassConstFetch
            || $expr instanceof Node\Expr\PropertyFetch && $this->isPure($expr->var);
    }

    /**
     * Значение инварианта для `$obj->prop` по типу получателя.
     *
     * @return array{mixed, bool}
     */
    private function valueOf(Node\Expr\PropertyFetch|Node\Expr\NullsafePropertyFetch $node): array
    {
        $types = $node->name instanceof Node\Identifier ? $this->inference?->typeOf($node->var) : null;
        if ($types === null || $types === [] || !$node->name instanceof Node\Identifier) {
            return [null, false];
        }

        // Все возможные классы получателя — с одним и тем же инвариантом этого свойства
        $value = null;
        $found = false;
        foreach ($types as $type) {
            [$typeValue, $typeFound] = $this->invariantFor($type, $node->name->toString());
            if (!$typeFound || $found && $typeValue !== $value) {
                return [null, false];
            }

            [$value, $found] = [$typeValue, true];
        }

        return [$value, $found];
    }

    /**
     * Инвариант для объектов типа: объявлен для самого класса или его предка.
     *
     * @return array{mixed, bool}
     */
    private function invariantFor(string $type, string $property): array
    {
        $class = $this->classes[$type] ?? null;
        if (!$class instanceof Node\Stmt\ClassLike || !$this->inference instanceof TypeInference) {
            return [null, false];
        }

        foreach (array_keys($this->inference->ancestors($class)) as $ancestor) {
            $declared = $this->classes[$ancestor] ?? null;
            $name     = $declared instanceof Node\Stmt\ClassLike && $declared->name instanceof Node\Identifier ? $declared->name->toString() : $ancestor;
            [$value, $found] = $this->environment->invariant($name, $property);
            if ($found) {
                return [$value, true];
            }
        }

        return [null, false];
    }

    /** Свойство записывается, передаётся по ссылке или проверяется isset/unset в этой позиции. */
    private function isWritten(Node\Expr $fetch): bool
    {
        $child = $fetch;
        for ($parent = $fetch->getAttribute('parent'); $parent instanceof Node; $parent = $parent->getAttribute('parent')) {
            $target = match (true) {
                $parent instanceof Node\Expr\Assign, $parent instanceof Node\Expr\AssignOp, $parent instanceof Node\Expr\AssignRef,
                $parent instanceof Node\Expr\PreInc, $parent instanceof Node\Expr\PreDec, $parent instanceof Node\Expr\PostInc,
                $parent instanceof Node\Expr\PostDec => $parent->var === $child,
                $parent instanceof Node\Stmt\Unset_, $parent instanceof Node\Expr\Isset_, $parent instanceof Node\Expr\Empty_ => true,
                $parent instanceof Node\Arg => $parent->byRef || $this->mayBeByRef($parent),
                $parent instanceof Node\Stmt\Foreach_ => $parent->byRef && $parent->expr === $child,
                default => null,
            };
            if ($target !== null) {
                return $target;
            }

            // $obj->prop[...] / $obj->prop->x — запись глубже меняет само свойство
            if (!$parent instanceof Node\Expr\ArrayDimFetch && !$parent instanceof Node\Expr\PropertyFetch || $parent->var !== $child) {
                return false;
            }

            $child = $parent;
        }

        return false;
    }

    /** Передача в вызов: по ссылке для встроенных функций — по рефлексии, для остальных неизвестно. */
    private function mayBeByRef(Node\Arg $arg): bool
    {
        $call = $arg->getAttribute('parent');
        if (!$call instanceof Node\Expr\FuncCall || !$call->name instanceof Node\Name || !function_exists($call->name->toString())) {
            return !$call instanceof Node\Expr\FuncCall && !$call instanceof Node\Expr\MethodCall && !$call instanceof Node\Expr\StaticCall
                && !$call instanceof Node\Expr\New_ && !$call instanceof Node\Expr\NullsafeMethodCall;
        }

        $position   = array_search($arg, $call->args, true);
        $parameters = new \ReflectionFunction($call->name->toString())->getParameters();
        $parameter  = $parameters[is_int($position) ? $position : 0] ?? end($parameters);

        return $parameter !== false && $parameter->isPassedByReference();
    }

    private function literal(mixed $value): ?Node\Expr
    {
        return match (true) {
            $value === null => new Node\Expr\ConstFetch(new Node\Name('null')),
            is_bool($value) => new Node\Expr\ConstFetch(new Node\Name($value ? 'true' : 'false')),
            is_int($value) => new Node\Scalar\Int_($value),
            is_float($value) => new Node\Scalar\Float_($value),
            is_string($value) => new Node\Scalar\String_($value),
            $value === [] => new Node\Expr\Array_([], ['kind' => Node\Expr\Array_::KIND_SHORT]),
            default => null,
        };
    }
}
