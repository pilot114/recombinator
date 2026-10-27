<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;
use Recombinator\Support\TypeInference;

/**
 * Сворачивает чтение ключа свойства-массива, который нигде не записывается:
 *
 *   public function isNativeLazyObjectsEnabled(): bool
 *   {
 *       return $this->attributes['nativeLazyObjects'] ?? false;   →  return false;
 *   }
 *
 * Записи учитываются по классу, объявляющему свойство (получатель — по выводу
 * типов); запись с неизвестным получателем относится ко всем свойствам с этим
 * именем. Мир замкнут: после встраивания классы плоские, внешний код к их свойствам
 * не обращается. Ключ записан, если есть `->p['k'] = …` (в том числе глубже:
 * `->p['k']['x'] = …`), `->p = [... 'k' => …]` или значение по умолчанию с этим
 * ключом. Свойство не анализируется, если в него пишут иначе: целиком не литеральным
 * массивом, по вычисляемому ключу, `->p[] = …`, по ссылке, передачей в функцию по
 * ссылке; динамическая запись `$obj->$name = …` отключает все свойства класса.
 *
 * Сворачиваются `->p['k'] ?? D` → D, `isset(->p['k'])` → false, `empty(->p['k'])` → true,
 * только для получателя — объекта класса программы с этим свойством.
 */
#[VisitorMeta('Чтение ключа массива-свойства, который нигде не записывается')]
class UnwrittenArrayKeyVisitor extends BaseVisitor
{
    /**
     * `класс::свойство` → записанные ключи; null — анализ невозможен. Класс `*` —
     * получатель неизвестен, свойство `*` — динамическая запись.
     *
     * @var array<string, array<string, true>|null>
     */
    private array $written = [];

    private ?TypeInference $inference = null;

    /** @var array<string, Node\Stmt\ClassLike> */
    private array $classes = [];

    #[\Override]
    public function beforeTraverse(array $nodes): ?array
    {
        parent::beforeTraverse($nodes);
        $this->written = [];
        $this->classes = [];
        foreach ($this->findNode(Node\Stmt\ClassLike::class) as $class) {
            if ($class->name instanceof Node\Identifier) {
                $this->classes[$class->name->toLowerString()] = $class;
            }
        }

        $this->inference = new TypeInference($this->classes, $nodes);

        foreach ($this->classes as $name => $class) {
            foreach ($class->getProperties() as $property) {
                foreach ($property->props as $item) {
                    $this->recordValue([$name], $item->name->toString(), $item->default);
                }
            }

            // Повышенный параметр конструктора — значение неизвестно
            foreach ($class->getMethod('__construct')->params ?? [] as $param) {
                if ($param->flags !== 0 && $param->var instanceof Node\Expr\Variable && is_string($param->var->name)) {
                    $this->written[$name . '::' . $param->var->name] = null;
                }
            }
        }

        foreach ($this->findNode(Node\Expr\PropertyFetch::class) as $fetch) {
            $this->recordFetch($fetch);
        }

        return null;
    }

    public function enterNode(Node $node): ?Node
    {
        if ($node instanceof Node\Expr\BinaryOp\Coalesce && $this->unwritten($node->left)) {
            return $node->right;
        }

        if ($node instanceof Node\Expr\Isset_ && count($node->vars) === 1 && $this->unwritten($node->vars[0])) {
            return new Node\Expr\ConstFetch(new Node\Name('false'));
        }

        if ($node instanceof Node\Expr\Empty_ && $this->unwritten($node->expr)) {
            return new Node\Expr\ConstFetch(new Node\Name('true'));
        }

        return null;
    }

    /** `->p['k']` с ключом, который в свойство p получателя никогда не записывается. */
    private function unwritten(Node\Expr $expr): bool
    {
        if (
            !$expr instanceof Node\Expr\ArrayDimFetch || !$expr->dim instanceof Node\Scalar\String_ && !$expr->dim instanceof Node\Scalar\Int_
            || !$expr->var instanceof Node\Expr\PropertyFetch || !$expr->var->name instanceof Node\Identifier
        ) {
            return false;
        }

        $property = $expr->var->name->toString();
        $owners   = $this->owners($expr->var->var, $property);
        if ($owners === null || $owners === []) {
            return false; // получатель неизвестен или внешний объект
        }

        $key = (string) $expr->dim->value;
        foreach (['*::' . $property, '*::*', ...array_map(static fn(string $o): string => $o . '::' . $property, $owners)] as $scope) {
            if (!array_key_exists($scope, $this->written)) {
                continue;
            }

            $keys = $this->written[$scope];
            if ($keys === null || isset($keys[$key])) {
                return false;
            }
        }

        return array_all($this->written, fn(?array $keys, $scope): bool => !($keys === null && str_ends_with($scope, '::*') && in_array($this->owner(substr($scope, 0, -3), $property), $owners, true)));
    }

    /**
     * Классы, объявляющие свойство, для всех возможных типов получателя; null —
     * тип неизвестен или у какого-то типа свойства в программе нет.
     *
     * @return list<string>|null
     */
    private function owners(Node\Expr $receiver, string $property): ?array
    {
        $types = $this->inference?->typeOf($receiver);
        if ($types === null || $types === []) {
            return null;
        }

        $owners = [];
        foreach ($types as $type) {
            $owner = $this->owner($type, $property);
            if ($owner === null) {
                return null;
            }

            $owners[] = $owner;
        }

        return array_values(array_unique($owners));
    }

    /** Класс программы (lowercase), объявляющий свойство для объектов этого типа. */
    private function owner(string $type, string $property, int $depth = 0): ?string
    {
        $class = $this->classes[$type] ?? null;
        if (!$class instanceof Node\Stmt\Class_ || $depth > 32) {
            return null;
        }

        if ($class->getProperty($property) instanceof Node\Stmt\Property) {
            return $type;
        }

        $constructor = $class->getMethod('__construct');
        if (
            $constructor instanceof Node\Stmt\ClassMethod && array_any(
                $constructor->params,
                static fn(Node\Param $p): bool => $p->flags !== 0 && $p->var instanceof Node\Expr\Variable && $p->var->name === $property,
            )
        ) {
            return $type;
        }

        return $class->extends instanceof Node\Name ? $this->owner(strtolower(ltrim($class->extends->toString(), '\\')), $property, $depth + 1) : null;
    }

    private function recordFetch(Node\Expr\PropertyFetch $fetch): void
    {
        // Подъём по индексам: ->p['k']['x'] — запись ключа 'k'
        $child  = $fetch;
        $first  = null;
        $parent = $fetch->getAttribute('parent');
        while ($parent instanceof Node\Expr\ArrayDimFetch && $parent->var === $child) {
            $first ??= $parent;
            $child  = $parent;
            $parent = $child->getAttribute('parent');
        }

        if (!$this->isWrite($child)) {
            return;
        }

        $types  = $this->inference?->typeOf($fetch->var);
        $scopes = $types === null || $types === [] ? ['*'] : $types;
        if (!$fetch->name instanceof Node\Identifier) {
            foreach ($scopes as $scope) {
                $this->written[$scope . '::*'] = null;
            }

            return;
        }

        $property = $fetch->name->toString();
        $owners   = $scopes === ['*'] ? ['*'] : array_map(fn(string $t): string => $this->owner($t, $property) ?? $t, $scopes);

        if (!$first instanceof Node\Expr\ArrayDimFetch) {
            // ->p = …: целиком; литеральный массив даёт свои ключи
            $assign = $child->getAttribute('parent');
            $this->recordValue($owners, $property, $assign instanceof Node\Expr\Assign && $assign->var === $child ? $assign->expr : $fetch);

            return;
        }

        foreach ($owners as $owner) {
            $scope = $owner . '::' . $property;
            if (array_key_exists($scope, $this->written) && $this->written[$scope] === null) {
                continue;
            }

            $dim = $first->dim;
            if ($dim instanceof Node\Scalar\String_ || $dim instanceof Node\Scalar\Int_) {
                $this->written[$scope][(string) $dim->value] = true;
            } else {
                $this->written[$scope] = null;
            }
        }
    }

    /**
     * Значение по умолчанию или присвоенное целиком: литеральный массив — его ключи,
     * null или отсутствие значения — ничего, иначе анализ невозможен.
     *
     * @param list<string> $owners
     */
    private function recordValue(array $owners, string $property, ?Node\Expr $value): void
    {
        $keys = [];
        if ($value instanceof Node\Expr\Array_) {
            foreach ($value->items as $item) {
                if ($item === null || $item->unpack || !$item->key instanceof Node\Scalar\String_ && !$item->key instanceof Node\Scalar\Int_) {
                    $keys = null;
                    break;
                }

                $keys[(string) $item->key->value] = true;
            }
        } elseif ($value instanceof Node\Expr && (!$value instanceof Node\Expr\ConstFetch || $value->name->toLowerString() !== 'null')) {
            $keys = null;
        }

        foreach ($owners as $owner) {
            $scope = $owner . '::' . $property;
            if (array_key_exists($scope, $this->written) && $this->written[$scope] === null) {
                continue;
            }

            $this->written[$scope] = $keys === null ? null : [...$this->written[$scope] ?? [], ...$keys];
        }
    }

    /** Позиция записи: присваивание, ++/--, ссылка, передача по ссылке; unset не считается. */
    private function isWrite(Node\Expr $expr): bool
    {
        $parent = $expr->getAttribute('parent');

        return match (true) {
            $parent instanceof Node\Expr\AssignRef => true,
            $parent instanceof Node\Expr\Assign, $parent instanceof Node\Expr\AssignOp,
            $parent instanceof Node\Expr\PreInc, $parent instanceof Node\Expr\PreDec, $parent instanceof Node\Expr\PostInc,
            $parent instanceof Node\Expr\PostDec => $parent->var === $expr,
            $parent instanceof Node\Arg => $this->byReference($parent),
            $parent instanceof Node\Expr\ClosureUse => $parent->byRef,
            $parent instanceof Node\Stmt\Foreach_ => $parent->byRef && $parent->expr === $expr || $parent->valueVar === $expr || $parent->keyVar === $expr,
            $parent instanceof Node\ArrayItem => $parent->byRef || $this->inListTarget($parent),
            default => false,
        };
    }

    /** Аргумент по ссылке: у встроенной функции — по рефлексии, у остальных — возможно. */
    private function byReference(Node\Arg $arg): bool
    {
        if ($arg->byRef) {
            return true;
        }

        $call = $arg->getAttribute('parent');
        if (!$call instanceof Node\Expr\FuncCall || !$call->name instanceof Node\Name || !function_exists($call->name->toString())) {
            return true;
        }

        $function = new \ReflectionFunction($call->name->toString());
        if (!$function->isInternal()) {
            return true;
        }

        $position   = array_search($arg, $call->args, true);
        $parameters = $function->getParameters();
        $parameter  = $parameters[is_int($position) ? $position : 0] ?? end($parameters);

        return $parameter !== false && $parameter->isPassedByReference();
    }

    private function inListTarget(Node $node): bool
    {
        for ($current = $node; $current instanceof Node; $current = $current->getAttribute('parent')) {
            $parent = $current->getAttribute('parent');
            if ($parent instanceof Node\Expr\Assign) {
                return $parent->var === $current;
            }

            if (!$parent instanceof Node\ArrayItem && !$parent instanceof Node\Expr\List_ && !$parent instanceof Node\Expr\Array_) {
                return false;
            }
        }

        return false;
    }
}
