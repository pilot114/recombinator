<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;

/**
 * Разворачивает backed-перечисления (enum с типом string/int):
 *
 * - `EnumName::Case`       → backing-значение (строковый/числовой литерал)
 * - `self::Case` внутри enum-метода → то же backing-значение
 * - `EnumName::staticMethod()`      → тело метода (если он состоит из одного return)
 * - Само определение enum удаляется после замены.
 *
 * Pure-enum (без backing type) не трогается — у него нет скалярного эквивалента.
 *
 * Значение case — объект, а не строка: замена корректна, только если программа
 * нигде не пользуется им как объектом. Enum остаётся, если его имя стоит в типе
 * (`MatchType $t = MatchType::SIMPLE` — строка в типизированном свойстве не компилируется),
 * в instanceof/::class/new, если вызывается метод, который здесь не разворачивается
 * (from, cases, …), или если в коде есть ->value, ->name и вызовы методов экземпляра enum.
 */
#[VisitorMeta('Разворачивание enum: EnumName::Case → backing-значение, удаление enum')]
class EnumCaseInlinerVisitor extends BaseVisitor
{
    /**
     * @var array<string, array{
     *   cases:   array<string, Node\Expr>,
     *   methods: array<string, Node\Stmt\ClassMethod>,
     *   type:    Node\Identifier
     * }>
     */
    private array $enums = [];

    /** Имя enum, тело которого сейчас обходится (для замены self::Case). */
    private ?string $currentEnum = null;

    #[\Override]
    public function beforeTraverse(array $nodes): ?array
    {
        parent::beforeTraverse($nodes);
        $this->enums       = [];
        $this->currentEnum = null;
        $this->collectEnums($nodes);
        if ($this->enums !== [] && $this->hasPendingIncludes()) {
            $this->enums = [];
        }

        if ($this->enums !== []) {
            $this->dropUsedAsObjects($nodes);
        }

        return null;
    }

    /**
     * Убирает из $enums перечисления, которые программа использует как объекты.
     *
     * Без анализа потока данных: выражение «может быть case enum E», если это E::X,
     * E::m(), переменная/свойство/метод, которым где-то присваивается или от которых
     * возвращается такое выражение, параметр или свойство с типом E. Имена
     * сопоставляются по всей программе — это грубее, но только в безопасную сторону.
     *
     * @param array<Node> $nodes
     */
    private function dropUsedAsObjects(array $nodes): void
    {
        $lower = [];
        foreach (array_keys($this->enums) as $name) {
            $lower[strtolower($name)] = $name;
        }

        $outside = [];
        foreach ($nodes as $node) {
            foreach ($node instanceof Node\Stmt\Namespace_ ? $node->stmts : [$node] as $stmt) {
                if (!$stmt instanceof Node\Stmt\Enum_) {
                    $outside[] = $stmt;
                }
            }
        }

        $all     = $this->findNode(Node::class, $outside);
        $blocked = [];
        // enum → имена переменных, свойств и методов, через которые может пройти его case
        $vars = [];
        $props = [];
        $methods = [];
        $mark = function (Node $target, string $enum) use (&$vars, &$props): void {
            while ($target instanceof Node\Expr\ArrayDimFetch) {
                $target = $target->var;
            }

            if ($target instanceof Node\Expr\Variable && is_string($target->name)) {
                $vars[$enum][$target->name] = true;
            } elseif (
                ($target instanceof Node\Expr\PropertyFetch || $target instanceof Node\Expr\StaticPropertyFetch)
                && $target->name instanceof Node\Identifier
            ) {
                $props[$enum][strtolower($target->name->toString())] = true;
            }
        };

        // Имена с типом enum и прямые case — основа; присваивания распространяют её до неподвижной точки
        foreach ($all as $node) {
            foreach ($this->typeNames($node) as $type) {
                $enum = $lower[strtolower($type->toString())] ?? null;
                if ($enum === null) {
                    continue;
                }

                match (true) {
                    $node instanceof Node\Param => $mark($node->var, $enum),
                    $node instanceof Node\Stmt\Property => array_map(
                        static function (Node\PropertyItem $item) use (&$props, $enum): void {
                            $props[$enum][strtolower($item->name->toString())] = true;
                        },
                        $node->props,
                    ),
                    $node instanceof Node\Stmt\ClassMethod => $methods[$enum][strtolower($node->name->toString())] = true,
                    $node instanceof Node\Stmt\Function_ => $methods[$enum]['fn:' . strtolower($node->name->toString())] = true,
                    default => null,
                };
            }

            if (($node instanceof Node\Expr\Instanceof_ || $node instanceof Node\Expr\New_) && $node->class instanceof Node\Name) {
                $blocked[strtolower($node->class->toString())] = true;
            }

            if (
                $node instanceof Node\Expr\StaticCall
                && $node->class instanceof Node\Name
                && isset($lower[strtolower($node->class->toString())])
                && !$this->isInlinableStaticCall($lower[strtolower($node->class->toString())], $node)
            ) {
                $blocked[strtolower($node->class->toString())] = true;
            }

            if (
                $node instanceof Node\Expr\ClassConstFetch
                && $node->class instanceof Node\Name
                && $node->name instanceof Node\Identifier
                && strtolower($node->name->toString()) === 'class'
            ) {
                $blocked[strtolower($node->class->toString())] = true;
            }
        }

        $mayBe = function (?Node $expr, string $enum) use (&$mayBe, &$vars, &$props, &$methods): bool {
            return match (true) {
                $expr instanceof Node\Expr\ClassConstFetch, $expr instanceof Node\Expr\StaticCall
                    => $expr->class instanceof Node\Name && strcasecmp($expr->class->toString(), $enum) === 0
                        || $expr instanceof Node\Expr\StaticCall && $expr->name instanceof Node\Identifier
                        && isset($methods[$enum][strtolower($expr->name->toString())]),
                $expr instanceof Node\Expr\Variable => is_string($expr->name) && isset($vars[$enum][$expr->name]),
                $expr instanceof Node\Expr\PropertyFetch, $expr instanceof Node\Expr\NullsafePropertyFetch,
                $expr instanceof Node\Expr\StaticPropertyFetch
                    => $expr->name instanceof Node\Identifier && isset($props[$enum][strtolower($expr->name->toString())]),
                $expr instanceof Node\Expr\MethodCall, $expr instanceof Node\Expr\NullsafeMethodCall
                    => $expr->name instanceof Node\Identifier && isset($methods[$enum][strtolower($expr->name->toString())]),
                $expr instanceof Node\Expr\FuncCall
                    => $expr->name instanceof Node\Name && isset($methods[$enum]['fn:' . strtolower($expr->name->toString())]),
                $expr instanceof Node\Expr\ArrayDimFetch => $mayBe($expr->var, $enum),
                $expr instanceof Node\Expr\BinaryOp\Coalesce => $mayBe($expr->left, $enum) || $mayBe($expr->right, $enum),
                $expr instanceof Node\Expr\Ternary => $mayBe($expr->if ?? $expr->cond, $enum) || $mayBe($expr->else, $enum),
                $expr instanceof Node\Expr\Match_ => array_any($expr->arms, static fn(Node\MatchArm $arm): bool => $mayBe($arm->body, $enum)),
                $expr instanceof Node\Expr\Assign => $mayBe($expr->expr, $enum),
                default => false,
            };
        };

        // Узлы, через которые распространяется значение, и обращения к членам — отдельно,
        // чтобы неподвижная точка не перебирала весь AST
        $flows   = [];
        /** @var list<Node\Expr\PropertyFetch|Node\Expr\NullsafePropertyFetch|Node\Expr\MethodCall|Node\Expr\NullsafeMethodCall> $fetches */
        $fetches = [];
        foreach ($all as $node) {
            if ($node instanceof Node\Expr\Assign || $node instanceof Node\Expr\AssignOp\Coalesce) {
                $flows[] = [$node->var, $node->expr, null];
            } elseif ($node instanceof Node\Stmt\Foreach_) {
                $flows[] = [$node->valueVar, $node->expr, null];
            } elseif ($node instanceof Node\Stmt\ClassMethod || $node instanceof Node\Stmt\Function_) {
                $key = ($node instanceof Node\Stmt\Function_ ? 'fn:' : '') . strtolower($node->name->toString());
                foreach ($this->findNode(Node\Stmt\Return_::class, $node->stmts ?? []) as $return) {
                    $flows[] = [null, $return->expr, $key];
                }
            } elseif (
                ($node instanceof Node\Expr\PropertyFetch || $node instanceof Node\Expr\NullsafePropertyFetch
                || $node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\NullsafeMethodCall)
                && $node->name instanceof Node\Identifier
            ) {
                $fetches[] = $node;
            }
        }

        foreach (array_keys($this->enums) as $enum) {
            do {
                $before = [count($vars[$enum] ?? []), count($props[$enum] ?? []), count($methods[$enum] ?? [])];
                foreach ($flows as [$target, $expr, $method]) {
                    if (!$expr instanceof Node || !$mayBe($expr, $enum)) {
                        continue;
                    }

                    if ($method !== null) {
                        $methods[$enum][$method] = true;
                    } elseif ($target instanceof Node) {
                        $mark($target, $enum);
                    }
                }
            } while ($before !== [count($vars[$enum] ?? []), count($props[$enum] ?? []), count($methods[$enum] ?? [])]);
        }

        foreach (array_keys($this->enums) as $enum) {
            $instanceMembers = ['value' => true, 'name' => true];
            foreach ($this->enums[$enum]['methods'] as $method) {
                if (!$method->isStatic()) {
                    $instanceMembers[strtolower($method->name->toString())] = true;
                }
            }

            $objectUse = array_any($fetches, static fn(Node $node): bool => $node->name instanceof Node\Identifier
                && isset($instanceMembers[strtolower($node->name->toString())])
                && $mayBe($node->var, $enum));

            if ($objectUse || isset($blocked[strtolower($enum)])) {
                unset($this->enums[$enum]);
            }
        }
    }

    /**
     * Имена классов в типе узла (параметр, возврат, свойство, константа).
     *
     * @return list<Node\Name>
     */
    private function typeNames(Node $node): array
    {
        $type = $this->typeOf($node);

        return $type instanceof Node ? $this->findNode(Node\Name::class, $type) : [];
    }

    private function typeOf(Node $node): Node\ComplexType|Node\Identifier|Node\Name|null
    {
        return match (true) {
            $node instanceof Node\Param, $node instanceof Node\Stmt\Property, $node instanceof Node\Stmt\ClassConst => $node->type,
            $node instanceof Node\FunctionLike => $node->getReturnType(),
            default => null,
        };
    }

    /** Заменяет встраиваемые enum в типе их скалярным типом; тот же узел — если менять нечего. */
    private function rewriteType(Node\ComplexType|Node\Identifier|Node\Name $type): Node\ComplexType|Node\Identifier|Node\Name
    {
        if ($type instanceof Node\Identifier || $type instanceof Node\Name) {
            return $this->rewriteName($type);
        }

        if ($type instanceof Node\NullableType) {
            $inner = $this->rewriteName($type->type);

            return $inner !== $type->type ? new Node\NullableType($inner) : $type;
        }

        if ($type instanceof Node\UnionType) {
            /** @var array<string, Node\Identifier|Node\IntersectionType|Node\Name> $types */
            $types   = [];
            $changed = false;
            foreach ($type->types as $member) {
                $new     = $member instanceof Node\IntersectionType ? $member : $this->rewriteName($member);
                $changed = $changed || $new !== $member;
                // string|string — ошибка компиляции, повтор убираем
                $types[strtolower(new \PhpParser\PrettyPrinter\Standard()->prettyPrint([$new]))] = $new;
            }

            return $changed ? new Node\UnionType(array_values($types)) : $type;
        }

        return $type;
    }

    private function rewriteName(Node\Identifier|Node\Name $type): Node\Identifier|Node\Name
    {
        $enum = $type instanceof Node\Name ? $this->enums[$type->toString()] ?? null : null;

        return $enum !== null ? new Node\Identifier($enum['type']->toString()) : $type;
    }

    private function isInlinableStaticCall(string $enum, Node\Expr\StaticCall $call): bool
    {
        $method = $call->name instanceof Node\Identifier ? ($this->enums[$enum]['methods'][$call->name->toString()] ?? null) : null;

        return $method instanceof Node\Stmt\ClassMethod
            && $method->stmts !== null
            && count($method->stmts) === 1
            && $method->stmts[0] instanceof Node\Stmt\Return_
            && $method->stmts[0]->expr instanceof Node\Expr;
    }

    /** @param array<Node> $nodes */
    private function collectEnums(array $nodes): void
    {
        foreach ($nodes as $node) {
            if (!$node instanceof Node\Stmt\Enum_) {
                continue;
            }

            if (!$node->name instanceof Node\Identifier) {
                continue;
            }

            if (!$node->scalarType instanceof \PhpParser\Node) {
                continue;
            }

            $cases   = [];
            $methods = [];

            foreach ($node->stmts as $stmt) {
                if ($stmt instanceof Node\Stmt\EnumCase && $stmt->name instanceof Node\Identifier && $stmt->expr instanceof \PhpParser\Node\Expr) {
                    $cases[$stmt->name->name] = $stmt->expr;
                }

                if ($stmt instanceof Node\Stmt\ClassMethod && $stmt->name instanceof Node\Identifier) {
                    $methods[$stmt->name->name] = $stmt;
                }
            }

            $this->enums[$node->name->name] = ['cases' => $cases, 'methods' => $methods, 'type' => $node->scalarType];
        }
    }

    public function enterNode(Node $node): int|Node|array|null
    {
        // Фиксируем контекст обхода тела enum
        if ($node instanceof Node\Stmt\Enum_ && $node->name instanceof Node\Identifier) {
            if (isset($this->enums[$node->name->name])) {
                $this->currentEnum = $node->name->name;
            }

            return null;
        }

        // Тип с enum → его скалярный тип: строка в свойстве `MatchType $t` не компилируется
        $type = $this->typeOf($node);
        if ($type instanceof Node && $this->enums !== []) {
            $rewritten = $this->rewriteType($type);
            if ($rewritten !== $type) {
                match (true) {
                    $node instanceof Node\Param, $node instanceof Node\Stmt\Property,
                    $node instanceof Node\Stmt\ClassConst => $node->type = $rewritten,
                    $node instanceof Node\Stmt\ClassMethod, $node instanceof Node\Stmt\Function_,
                    $node instanceof Node\Expr\Closure, $node instanceof Node\Expr\ArrowFunction => $node->returnType = $rewritten,
                    default => null,
                };
            }
        }

        // EnumName::CaseName → backing-значение
        if (
            $node instanceof Node\Expr\ClassConstFetch
            && $node->class instanceof Node\Name
            && $node->name instanceof Node\Identifier
        ) {
            $className = $node->class->toString();
            $constName = $node->name->name;

            // self::Case внутри тела enum
            if (strtolower($className) === 'self' && $this->currentEnum !== null) {
                $className = $this->currentEnum;
            }

            if (isset($this->enums[$className]['cases'][$constName])) {
                return $this->deepClone($this->enums[$className]['cases'][$constName]);
            }
        }

        // EnumName::staticMethod() — инлайн однострочного static-метода
        if (
            $node instanceof Node\Expr\StaticCall
            && $node->class instanceof Node\Name
            && $node->name instanceof Node\Identifier
        ) {
            $className  = $node->class->toString();
            $methodName = $node->name->name;

            if (isset($this->enums[$className]['methods'][$methodName])) {
                $method = $this->enums[$className]['methods'][$methodName];
                if (
                    $method->stmts !== null
                    && count($method->stmts) === 1
                    && $method->stmts[0] instanceof Node\Stmt\Return_
                    && $method->stmts[0]->expr instanceof Node\Expr
                ) {
                    return $this->deepClone($method->stmts[0]->expr);
                }
            }
        }

        return null;
    }

    #[\Override]
    public function leaveNode(Node $node): int|Node|array|null
    {
        if ($node instanceof Node\Stmt\Enum_ && $node->name instanceof Node\Identifier && isset($this->enums[$node->name->name])) {
            $this->currentEnum = null;
            $node->setAttribute('remove', true);
        }

        return parent::leaveNode($node);
    }
}
