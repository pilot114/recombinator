<?php

declare(strict_types=1);

namespace Recombinator\Support;

use PhpParser\Node;
use PhpParser\NodeFinder;

/**
 * Конечное множество строк, которые может дать выражение, — для имён классов,
 * собранных во время выполнения:
 *
 *   function findProviderClassname($provider, $locale = '') {
 *       $name = $locale ? sprintf('Provider\%s\%s', $locale, $provider) : sprintf('Provider\%s', $provider);
 *       return class_exists("Faker\\{$name}") ? … : null;
 *   }
 *   findProviderClassname('Address', 'en_US'); findProviderClassname('Address');
 *   →  {'Faker\Provider\en_US\Address', 'Faker\Provider\Address'}
 *
 * Учитываются литералы, конкатенация и интерполяция, sprintf с литеральным форматом
 * (%s), тернарный оператор и `??` (объединение веток), переменная — все её
 * присваивания в функции, параметр функции — аргументы во всех местах вызова и
 * значение по умолчанию. Прочее (данные, свойства, параметр метода) — неизвестно (null).
 */
final class StringValues
{
    private const int LIMIT = 256;

    /** @var array<string, list<Node\Expr\CallLike>>|null `fn:имя` — функции, имя — методы */
    private ?array $calls = null;

    /** @var array<int, list<string>|null> */
    private array $memo = [];

    /** @var array<string, Node\Expr>|null `класс::КОНСТАНТА` → значение */
    private ?array $constants = null;

    /** @param array<Node> $ast */
    public function __construct(private readonly array $ast)
    {
    }

    /**
     * Имена классов (FQCN, как в коде), собранные во время выполнения: аргумент
     * `class_exists()` и т.п. или `new (…)` с конечным набором значений.
     *
     * @param  array<Node> $nodes
     * @return list<string>
     */
    public static function classNames(array $nodes): array
    {
        $values = new self($nodes);
        $names  = [];
        foreach (self::classNameSites($nodes) as $expr) {
            foreach ($values->of($expr) ?? [] as $value) {
                $value = ltrim($value, '\\');
                if (preg_match('/^[A-Za-z_\x80-\xff][\w\x80-\xff]*(?:\\\\[A-Za-z_\x80-\xff][\w\x80-\xff]*)+$/', $value) === 1) {
                    $names[$value] = true;
                }
            }
        }

        return array_map(strval(...), array_keys($names));
    }

    /**
     * Выражения, значение которых — имя класса: аргумент class_exists() и т.п., `new (…)`.
     *
     * @param  array<Node> $nodes
     * @return list<Node\Expr>
     */
    public static function classNameSites(array $nodes): array
    {
        $sites = [];
        foreach (
            new NodeFinder()->find($nodes, static fn(Node $n): bool => $n instanceof Node\Expr\New_ && $n->class instanceof Node\Expr
                || $n instanceof Node\Expr\FuncCall && $n->name instanceof Node\Name
                && in_array($n->name->toLowerString(), ['class_exists', 'interface_exists', 'enum_exists', 'trait_exists'], true)) as $node
        ) {
            $expr = $node instanceof Node\Expr\New_ ? $node->class : (($node->args[0] ?? null) instanceof Node\Arg ? $node->args[0]->value : null);
            if ($expr instanceof Node\Expr && !$expr instanceof Node\Scalar\String_) {
                $sites[] = $expr;
            }
        }

        return $sites;
    }

    /** @return list<string>|null */
    public function of(Node\Expr $expr, int $depth = 0): ?array
    {
        if ($depth > 12) {
            return null;
        }

        $key = spl_object_id($expr);
        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        $this->memo[$key] = null; // цикл — неизвестно
        $result           = $this->compute($expr, $depth + 1);
        if ($result !== null && count($result) > self::LIMIT) {
            $result = null;
        }

        return $this->memo[$key] = $result === null ? null : array_values(array_unique($result));
    }

    /** @return list<string>|null */
    private function compute(Node\Expr $expr, int $depth): ?array
    {
        return match (true) {
            $expr instanceof Node\Scalar\String_ => [$expr->value],
            $expr instanceof Node\Scalar\Int_ => [(string) $expr->value],
            $expr instanceof Node\Expr\BinaryOp\Concat => $this->product([$this->of($expr->left, $depth), $this->of($expr->right, $depth)]),
            $expr instanceof Node\Scalar\InterpolatedString => $this->product(array_map(
                fn(Node $part): ?array => $part instanceof Node\InterpolatedStringPart ? [$part->value] : ($part instanceof Node\Expr ? $this->of($part, $depth) : null),
                $expr->parts,
            )),
            $expr instanceof Node\Expr\Ternary => $this->union([$expr->if instanceof Node\Expr ? $this->of($expr->if, $depth) : $this->of($expr->cond, $depth), $this->of($expr->else, $depth)]),
            $expr instanceof Node\Expr\BinaryOp\Coalesce => $this->union([$this->of($expr->left, $depth), $this->of($expr->right, $depth)]),
            $expr instanceof Node\Expr\FuncCall => $this->sprintf($expr, $depth),
            $expr instanceof Node\Expr\Variable && is_string($expr->name) => $this->variable($expr, $depth),
            $expr instanceof Node\Expr\ClassConstFetch => $this->classConstant($expr, $depth),
            // Элемент переменной без значений (переменная обхода пустого массива) — тоже без значений
            $expr instanceof Node\Expr\ArrayDimFetch => $this->of($expr->var, $depth) === [] ? [] : null,
            default => null,
        };
    }

    /** Константа класса программы с литеральным значением. @return list<string>|null */
    private function classConstant(Node\Expr\ClassConstFetch $fetch, int $depth): ?array
    {
        if (!$fetch->class instanceof Node\Name || !$fetch->name instanceof Node\Identifier || in_array($fetch->class->toLowerString(), ['static', 'parent'], true)) {
            return null;
        }

        if ($this->constants === null) {
            $this->constants = [];
            foreach (new NodeFinder()->findInstanceOf($this->ast, Node\Stmt\ClassLike::class) as $class) {
                foreach ($class->getConstants() as $group) {
                    foreach ($group->consts as $const) {
                        if ($class->name instanceof Node\Identifier) {
                            $this->constants[$class->name->toLowerString() . '::' . $const->name->toString()] = $const->value;
                        }
                    }
                }
            }
        }

        $class = $fetch->class->toLowerString();
        if ($class === 'self') {
            $owner = $fetch->getAttribute('parent');
            while ($owner instanceof Node && !$owner instanceof Node\Stmt\ClassLike) {
                $owner = $owner->getAttribute('parent');
            }

            $class = $owner instanceof Node\Stmt\ClassLike && $owner->name instanceof Node\Identifier ? $owner->name->toLowerString() : '';
        }

        $value = $this->constants[ltrim($class, '\\') . '::' . $fetch->name->toString()] ?? null;

        return $value instanceof Node\Expr ? $this->of($value, $depth) : null;
    }

    /** @return list<string>|null */
    private function sprintf(Node\Expr\FuncCall $call, int $depth): ?array
    {
        if (
            !$call->name instanceof Node\Name || $call->name->toLowerString() !== 'sprintf'
            || !($call->args[0] ?? null) instanceof Node\Arg || !$call->args[0]->value instanceof Node\Scalar\String_
        ) {
            return null;
        }

        $format = $call->args[0]->value->value;
        if (preg_match('/%(?!s|%)/', $format) === 1) {
            return null;
        }

        $pieces = preg_split('/(%s|%%)/', $format, -1, PREG_SPLIT_DELIM_CAPTURE);
        $parts  = [];
        $arg    = 1;
        foreach ($pieces === false ? [] : $pieces as $piece) {
            if ($piece === '%%') {
                $parts[] = ['%'];
            } elseif ($piece === '%s') {
                $value   = $call->args[$arg++] ?? null;
                $parts[] = $value instanceof Node\Arg && !$value->unpack ? $this->of($value->value, $depth) : null;
            } else {
                $parts[] = [$piece];
            }
        }

        return $this->product($parts);
    }

    /**
     * Локальная переменная — все присваивания; параметр функции — аргументы вызовов.
     *
     * @return list<string>|null
     */
    private function variable(Node\Expr\Variable $variable, int $depth): ?array
    {
        $function = $variable->getAttribute('parent');
        while ($function instanceof Node && !$function instanceof Node\FunctionLike) {
            $function = $function->getAttribute('parent');
        }

        if (!$function instanceof Node\FunctionLike) {
            return null;
        }

        $name = $variable->name;
        /** @var list<list<string>|null> $results */
        $results = [];
        foreach (array_values($function->getParams()) as $index => $param) {
            if ($param->var instanceof Node\Expr\Variable && $param->var->name === $name) {
                if ($param->byRef || $param->variadic || !$function instanceof Node\Stmt\Function_ && !$function instanceof Node\Stmt\ClassMethod) {
                    return null;
                }

                $results[] = $this->argument($function, $index, $param, $depth);
            }
        }

        foreach (new NodeFinder()->find($function->getStmts() ?? [], static fn(Node $n): bool => $n instanceof Node\Expr\Variable && $n->name === $name) as $use) {
            $parent = $use->getAttribute('parent');
            if ($parent instanceof Node\Expr\Assign && $parent->var === $use) {
                $results[] = $this->of($parent->expr, $depth);
            } elseif ($parent instanceof Node\Stmt\Foreach_ && $parent->valueVar === $use && $this->emptyIterable($parent->expr)) {
                $results[] = []; // обход пустого массива — переменная не получает значений
            } elseif (
                $parent instanceof Node\Expr\AssignOp || $parent instanceof Node\Expr\AssignRef || $parent instanceof Node\Stmt\Foreach_
                || $parent instanceof Node\Arg && $parent->byRef || $parent instanceof Node\Expr\ClosureUse && $parent->byRef
                || $parent instanceof Node\ArrayItem || $parent instanceof Node\Stmt\Global_ || $parent instanceof Node\Stmt\StaticVar
            ) {
                return null;
            }
        }

        return $results === [] ? null : $this->union($results);
    }

    /**
     * Значения параметра функции по всем местам вызова.
     *
     * @return list<string>|null
     */
    private function argument(Node\Stmt\Function_|Node\Stmt\ClassMethod $function, int $index, Node\Param $param, int $depth): ?array
    {
        if ($this->calls === null) {
            $this->calls = [];
            foreach (new NodeFinder()->find($this->ast, static fn(Node $n): bool => $n instanceof Node\Expr\CallLike || $n instanceof Node\Scalar\String_) as $call) {
                if ($call instanceof Node\Expr\FuncCall && $call->name instanceof Node\Name) {
                    $this->calls['fn:' . strtolower(ltrim($call->name->toString(), '\\'))][] = $call;
                } elseif (
                    ($call instanceof Node\Expr\MethodCall || $call instanceof Node\Expr\NullsafeMethodCall || $call instanceof Node\Expr\StaticCall)
                    && $call->name instanceof Node\Identifier
                ) {
                    $this->calls[$call->name->toLowerString()][] = $call;
                } elseif ($call instanceof Node\Scalar\String_ && $this->callableString($call)) {
                    // Строка-callable: метод вызывается с неизвестными аргументами
                    $this->calls['str:' . strtolower(substr((string) strrchr(':' . $call->value, ':'), 1))] = [];
                }
            }
        }

        // Метод — по всем вызовам с этим именем (надмножество настоящих); вызов по строке или
        // по вычисленному имени — аргументы неизвестны
        $key = $function instanceof Node\Stmt\Function_ ? 'fn:' . $function->name->toLowerString() : $function->name->toLowerString();
        if ($function instanceof Node\Stmt\ClassMethod && (isset($this->calls['str:' . $key]) || str_starts_with($key, '__'))) {
            return null;
        }

        $calls = $this->calls[$key] ?? [];
        if ($calls === []) {
            return null;
        }

        $required = count(array_filter($function->getParams(), static fn(Node\Param $p): bool => !$p->default instanceof \PhpParser\Node\Expr && !$p->variadic));
        $values   = [];
        foreach ($calls as $call) {
            if ($call->isFirstClassCallable()) {
                return null;
            }

            $args = $call->getArgs();
            if (array_any($args, static fn(Node\Arg $a): bool => $a->unpack || $a->name instanceof Node\Identifier)) {
                return null;
            }

            // Одноимённый метод другого класса: этому методу аргументов не хватило бы
            if ($function instanceof Node\Stmt\ClassMethod && count($args) < $required) {
                continue;
            }

            $values[] = isset($args[$index]) ? $this->of($args[$index]->value, $depth) : ($param->default instanceof Node\Expr ? $this->of($param->default, $depth) : null);
        }

        return $this->union($values);
    }

    /** Литеральный пустой массив или элемент такого массива (`[]`, `[][$k]`). */
    private function emptyIterable(Node\Expr $expr): bool
    {
        while ($expr instanceof Node\Expr\ArrayDimFetch) {
            $expr = $expr->var;
        }

        return $expr instanceof Node\Expr\Array_ && $expr->items === [];
    }

    /** 'Класс::метод' или второй элемент `[$obj, 'метод']`. */
    private function callableString(Node\Scalar\String_ $string): bool
    {
        if (str_contains($string->value, '::')) {
            return true;
        }

        $item  = $string->getAttribute('parent');
        $array = $item instanceof Node\ArrayItem ? $item->getAttribute('parent') : null;

        return $array instanceof Node\Expr\Array_ && count($array->items) === 2 && $array->items[1] === $item;
    }

    /**
     * @param  list<list<string>|null> $sets
     * @return list<string>|null
     */
    private function union(array $sets): ?array
    {
        $result = [];
        foreach ($sets as $set) {
            if ($set === null) {
                return null;
            }

            array_push($result, ...$set);
        }

        return $result;
    }

    /**
     * @param  list<list<string>|null> $sets
     * @return list<string>|null
     */
    private function product(array $sets): ?array
    {
        $result = [''];
        foreach ($sets as $set) {
            if ($set === null || count($result) * count($set) > self::LIMIT) {
                return null;
            }

            $next = [];
            foreach ($result as $prefix) {
                foreach ($set as $value) {
                    $next[] = $prefix . $value;
                }
            }

            $result = $next;
        }

        return $result;
    }
}
