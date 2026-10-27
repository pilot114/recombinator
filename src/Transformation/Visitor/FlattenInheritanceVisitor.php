<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * Уплощает иерархию: копирует свойства/константы/методы/use-trait из родительского
 * класса в дочерний (если они не переопределены) и удаляет `extends`.
 *
 * За один проход обрабатывается ОДИН уровень иерархии: ребёнок инлайнится только
 * если у его родителя нет своего `extends`. Грандпарент достанется следующим
 * проходом фиксированной точки, когда родитель уже станет плоским.
 *
 * Что копируется:
 *   - use-trait;
 *   - неперекрытые свойства, константы, методы;
 *   - абстрактные методы пропускаются.
 *
 * Что с `parent::method(...)`:
 *   - оригинальный метод родителя копируется в дочерний под именем
 *     `__parent_<method>`, вызов переписывается:
 *     - static-метод → `self::__parent_M(...)`;
 *     - обычный      → `$this->__parent_M(...)`.
 *   - при копировании конструктора под синтетическим именем promotion-флаги
 *     родительских параметров снимаются (вне `__construct` они невалидны),
 *     эмитятся отдельные Property + ручные присваивания в начало тела.
 *
 * `implements` родителя мержится в дочерний; `extends` удаляется.
 *
 * Статические свойства родителя не копируются: в PHP это одно хранилище на всю
 * иерархию (`static::$registry[static::class]++` в конструкторе копит счётчики всех
 * подклассов). Обращения к ним в дочернем классе переписываются на `Parent::$prop`,
 * а свойство у родителя становится public — подкласс больше не наследник.
 *
 * Ограничения v1:
 *   - `parent::CONST` и `parent::$staticProp` не переписываются;
 *   - `self::` внутри скопированного тела теперь резолвит в дочерний класс —
 *     поведение меняется, если ребёнок переопределяет соответствующий символ;
 *   - конфликты имён трейтов (`insteadof`/`as`) не разруливаются.
 */
#[VisitorMeta('Уплощение наследования: копирование членов родителя в дочерний + inline parent::')]
class FlattenInheritanceVisitor extends BaseVisitor
{
    /** @var array<string, Node\Stmt\Class_> */
    private array $classMap = [];

    #[\Override]
    public function beforeTraverse(array $nodes): ?array
    {
        parent::beforeTraverse($nodes);
        $this->classMap = [];
        $this->collectClasses($nodes);
        return null;
    }

    #[\Override]
    public function afterTraverse(array $nodes): ?array
    {
        // Встраиваемый позже файл может проверять тип родителя
        if ($this->hasPendingIncludes()) {
            return null;
        }

        $typeChecked = null;
        $usage       = null;
        foreach ($this->classMap as $child) {
            if (!$child->extends instanceof Node\Name) {
                continue;
            }

            $parent = $this->classMap[$child->extends->toString()] ?? null;
            if ($parent === null) {
                continue; // внешний родитель — не достать тело
            }

            if ($parent->extends instanceof Node\Name) {
                continue; // ждём, пока родитель сам станет плоским
            }

            // Без extends подкласс перестаёт быть экземпляром родителя: `$x instanceof Parent`,
            // `f(Parent $p)`, `catch (Parent)` изменили бы поведение. Ждём, пока такие проверки
            // уберут другие правила (StripLocalClassTypeHints, InstanceofSimplifier)
            $typeChecked ??= RemoveInterfacesVisitor::typeReferences($nodes, withStaticAccess: false);
            if (isset($typeChecked[strtolower($child->extends->toString())]) || $this->usesParentAsType($child)) {
                continue;
            }

            // Копия тел родителя в каждого наследника только раздувает код: уплощаем,
            // лишь когда родитель исчезнет — у него один наследник и других ссылок нет
            $usage ??= $this->parentUsage($nodes);
            $parentKey = strtolower($child->extends->toString());
            if (($usage['children'][$parentKey] ?? 0) !== 1 || isset($usage['referenced'][$parentKey])) {
                continue;
            }

            $this->flatten($child, $parent);
        }

        return null;
    }

    /**
     * Сколько у каждого класса наследников и какие классы используются не только как
     * родитель (new, ::, строкой).
     *
     * @param  array<Node> $nodes
     * @return array{children: array<string, int>, referenced: array<string, true>}
     */
    private function parentUsage(array $nodes): array
    {
        $children   = [];
        $referenced = [];
        foreach ($this->findNode(Node::class, $nodes) as $node) {
            if ($node instanceof Node\Stmt\Class_ && $node->extends instanceof Node\Name) {
                $key            = strtolower($node->extends->toString());
                $children[$key] = ($children[$key] ?? 0) + 1;
            }

            $class = match (true) {
                $node instanceof Node\Expr\New_, $node instanceof Node\Expr\StaticCall, $node instanceof Node\Expr\ClassConstFetch,
                $node instanceof Node\Expr\StaticPropertyFetch => $node->class,
                default => null,
            };
            if ($class instanceof Node\Name) {
                $referenced[strtolower(ltrim($class->toString(), '\\'))] = true;
            } elseif ($node instanceof Node\Scalar\String_) {
                $referenced[strtolower(ltrim($node->value, '\\'))] = true;
            }
        }

        return ['children' => $children, 'referenced' => $referenced];
    }

    /** `instanceof parent`, `new parent`, `parent::CONST` — всё, кроме parent::method(). */
    private function usesParentAsType(Node\Stmt\Class_ $child): bool
    {
        foreach ($this->findNode(Node\Name::class, $child->stmts) as $name) {
            if (strtolower($name->toString()) !== 'parent') {
                continue;
            }

            $holder = $name->getAttribute('parent');
            if (!$holder instanceof Node\Expr\StaticCall || $holder->class !== $name) {
                return true;
            }
        }

        return false;
    }

    /** @param array<Node> $nodes */
    private function collectClasses(array $nodes): void
    {
        foreach ($nodes as $node) {
            if ($node instanceof Node\Stmt\Class_ && $node->name instanceof Node\Identifier) {
                $this->classMap[$node->name->name] = $node;
                continue;
            }

            if ($node instanceof Node\Stmt\Namespace_) {
                $this->collectClasses($node->stmts);
            }
        }
    }

    private function flatten(Node\Stmt\Class_ $child, Node\Stmt\Class_ $parent): void
    {
        $childMethods = $this->methodNames($child);
        $childProps   = $this->propertyNames($child);
        $childConsts  = $this->constNames($child);
        $parentCalls  = $this->parentMethodCallTargets($child);
        $parentStatic = $this->staticMethodMap($parent);

        // Имя копии родительского метода: __parent_M, а если такое уже есть (родитель
        // сам был наследником и содержит __parent_M деда) — __parent___parent_M и т.д.
        $taken         = $childMethods + $this->methodNames($parent);
        $parentMethods = [];
        foreach ($parent->getMethods() as $method) {
            if (!$method->isAbstract()) {
                $parentMethods[$method->name->toString()] = true;
            }
        }

        $synthetic = [];
        foreach (array_keys($parentCalls) as $name) {
            // Не переопределён в ребёнке — parent::M() это унаследованная копия M
            if (!isset($childMethods[$name])) {
                $synthetic[$name] = $name;
                continue;
            }

            // Метод родителя из трейта или не найден — копию __parent_M не построить
            if (!isset($parentMethods[$name])) {
                return;
            }

            $candidate = '__parent_' . $name;
            while (isset($taken[$candidate])) {
                $candidate = '__parent_' . $candidate;
            }

            $taken[$candidate] = true;
            $synthetic[$name]  = $candidate;
        }

        $copied        = [];
        $sharedStatics = [];

        foreach ($parent->stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\TraitUse) {
                $copied[] = $this->deepClone($stmt);
                continue;
            }

            if ($stmt instanceof Node\Stmt\ClassConst) {
                $kept = [];
                foreach ($stmt->consts as $const) {
                    if (!isset($childConsts[$const->name->name])) {
                        $kept[] = $const;
                    }
                }

                if ($kept !== []) {
                    $copy = $this->deepClone($stmt);
                    $copy->consts = $kept;
                    $copied[] = $copy;
                }

                continue;
            }

            if ($stmt instanceof Node\Stmt\Property && $stmt->isStatic()) {
                foreach ($stmt->props as $prop) {
                    if (!isset($childProps[$prop->name->name])) {
                        $sharedStatics[$prop->name->name] = true;
                    }
                }

                if ($sharedStatics !== []) {
                    $stmt->flags = $stmt->flags & ~(Modifiers::PRIVATE | Modifiers::PROTECTED) | Modifiers::PUBLIC;
                }

                continue;
            }

            if ($stmt instanceof Node\Stmt\Property) {
                $kept = [];
                foreach ($stmt->props as $prop) {
                    if (!isset($childProps[$prop->name->name])) {
                        $kept[] = $prop;
                        $childProps[$prop->name->name] = true;
                    }
                }

                if ($kept !== []) {
                    $copy = $this->deepClone($stmt);
                    $copy->props = $kept;
                    $copied[] = $copy;
                }

                continue;
            }

            if (!$stmt instanceof Node\Stmt\ClassMethod) {
                continue;
            }

            if ($stmt->isAbstract()) {
                continue;
            }

            $name = $stmt->name->name;
            if (!isset($childMethods[$name])) {
                // Не переопределён — копируем как есть. Promoted-параметры
                // конструктора остаются promoted (валидно только в __construct,
                // но здесь имя метода сохраняется, так что всё ок).
                $copied[] = $this->deepClone($stmt);
                continue;
            }

            if (!isset($parentCalls[$name])) {
                // Переопределён и parent:: не вызывают — родительская версия не нужна.
                continue;
            }

            // Переопределён и есть parent::M() — копируем как __parent_M.
            $copied[] = $this->buildParentCopy($stmt, $synthetic[$name], $childProps, $copied);
        }

        $child->stmts = array_merge($copied, $child->stmts);
        $this->redirectSharedStatics($child, $sharedStatics, (string) $parent->name);
        $this->rewriteParentCalls($child, $synthetic, $parentStatic);
        $this->mergeImplements($child, $parent);
        $child->extends = null;
    }

    /**
     * self::$p / static::$p / parent::$p → Parent::$p для статических свойств родителя.
     *
     * @param array<string, true> $names
     */
    private function redirectSharedStatics(Node\Stmt\Class_ $child, array $names, string $parentName): void
    {
        if ($names === []) {
            return;
        }

        foreach ($this->findNode(Node\Expr\StaticPropertyFetch::class, $child->stmts) as $fetch) {
            if (
                $fetch->class instanceof Node\Name
                && in_array(strtolower($fetch->class->toString()), ['self', 'static', 'parent'], true)
                && $fetch->name instanceof Node\VarLikeIdentifier
                && isset($names[$fetch->name->toString()])
            ) {
                $fetch->class = new Node\Name($parentName);
            }
        }
    }

    /**
     * Создаёт копию родительского метода под именем `__parent_<name>`.
     * Снимает promotion-флаги с параметров (вне __construct они невалидны),
     * добавляет в $copied соответствующие Property-узлы и эмитит ручное
     * присваивание в начало тела.
     *
     * @param array<string, true>    $childProps  будет мутирован: добавятся имена эмитнутых свойств
     * @param array<int, Node\Stmt>  $copied      будет мутирован: добавятся Property-узлы
     */
    private function buildParentCopy(
        Node\Stmt\ClassMethod $method,
        string $copyName,
        array &$childProps,
        array &$copied,
    ): Node\Stmt\ClassMethod {
        $copy = $this->deepClone($method);
        $copy->name = new Node\Identifier($copyName);

        $assigns = [];
        foreach ($copy->params as $param) {
            if ($param->flags === 0) {
                continue;
            }

            if (!$param->var instanceof Node\Expr\Variable) {
                continue;
            }

            if (!is_string($param->var->name)) {
                continue;
            }

            $pname = $param->var->name;
            if (!isset($childProps[$pname])) {
                $copied[] = new Node\Stmt\Property(
                    $param->flags,
                    [new Node\PropertyItem($pname)],
                    [],
                    $param->type,
                );
                $childProps[$pname] = true;
            }

            $assigns[] = new Node\Stmt\Expression(
                new Node\Expr\Assign(
                    new Node\Expr\PropertyFetch(new Node\Expr\Variable('this'), $pname),
                    new Node\Expr\Variable($pname),
                ),
            );
            $param->flags = 0;
        }

        $copy->stmts = array_merge($assigns, $copy->stmts ?? []);
        return $copy;
    }

    /** @return array<string, true> */
    private function methodNames(Node\Stmt\Class_ $class): array
    {
        $names = [];
        foreach ($class->stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\ClassMethod && $stmt->name instanceof Node\Identifier) {
                $names[$stmt->name->name] = true;
            }
        }

        return $names;
    }

    /** @return array<string, true> */
    private function propertyNames(Node\Stmt\Class_ $class): array
    {
        $names = [];
        foreach ($class->stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\Property) {
                foreach ($stmt->props as $prop) {
                    $names[$prop->name->name] = true;
                }

                continue;
            }

            if (
                $stmt instanceof Node\Stmt\ClassMethod
                && $stmt->name instanceof Node\Identifier
                && $stmt->name->name === '__construct'
            ) {
                foreach ($stmt->params as $param) {
                    if (
                        $param->flags !== 0
                        && $param->var instanceof Node\Expr\Variable
                        && is_string($param->var->name)
                    ) {
                        $names[$param->var->name] = true;
                    }
                }
            }
        }

        return $names;
    }

    /** @return array<string, true> */
    private function constNames(Node\Stmt\Class_ $class): array
    {
        $names = [];
        foreach ($class->stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\ClassConst) {
                foreach ($stmt->consts as $const) {
                    $names[$const->name->name] = true;
                }
            }
        }

        return $names;
    }

    /** @return array<string, true> */
    private function parentMethodCallTargets(Node\Stmt\Class_ $child): array
    {
        $collector = new class extends NodeVisitorAbstract {
            /** @var array<string, true> */
            public array $targets = [];

            public function enterNode(Node $n): null
            {
                if (
                    $n instanceof Node\Expr\StaticCall
                    && $n->class instanceof Node\Name
                    && strtolower($n->class->toString()) === 'parent'
                    && $n->name instanceof Node\Identifier
                ) {
                    $this->targets[$n->name->name] = true;
                }

                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($collector);
        $traverser->traverse($child->stmts);

        return $collector->targets;
    }

    /** @return array<string, bool> */
    private function staticMethodMap(Node\Stmt\Class_ $class): array
    {
        $map = [];
        foreach ($class->stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\ClassMethod && $stmt->name instanceof Node\Identifier) {
                $map[$stmt->name->name] = $stmt->isStatic();
            }
        }

        return $map;
    }

    /**
     * @param array<string, string> $targets  метод родителя → имя его копии в наследнике
     * @param array<string, bool> $staticMap
     */
    private function rewriteParentCalls(Node\Stmt\Class_ $child, array $targets, array $staticMap): void
    {
        if ($targets === []) {
            return;
        }

        $rewriter = new class ($targets, $staticMap) extends NodeVisitorAbstract {
            /**
             * @param array<string, string> $targets
             * @param array<string, bool> $staticMap
             */
            public function __construct(
                private readonly array $targets,
                private readonly array $staticMap,
            ) {
            }

            public function leaveNode(Node $n): ?Node
            {
                if (
                    !$n instanceof Node\Expr\StaticCall
                    || !$n->class instanceof Node\Name
                    || strtolower($n->class->toString()) !== 'parent'
                    || !$n->name instanceof Node\Identifier
                ) {
                    return null;
                }

                $name = $n->name->name;
                if (!isset($this->targets[$name])) {
                    return null;
                }

                $synthetic = new Node\Identifier($this->targets[$name]);
                if ($this->staticMap[$name] ?? false) {
                    return new Node\Expr\StaticCall(
                        new Node\Name('self'),
                        $synthetic,
                        $n->args,
                        $n->getAttributes(),
                    );
                }

                return new Node\Expr\MethodCall(
                    new Node\Expr\Variable('this'),
                    $synthetic,
                    $n->args,
                    $n->getAttributes(),
                );
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($rewriter);

        $child->stmts = $traverser->traverse($child->stmts);
    }

    private function mergeImplements(Node\Stmt\Class_ $child, Node\Stmt\Class_ $parent): void
    {
        foreach ($parent->implements as $impl) {
            $name = $impl->toString();
            $duplicate = array_any($child->implements, fn(\PhpParser\Node\Name $existing): bool => $existing->toString() === $name);
            if (!$duplicate) {
                $child->implements[] = $impl;
            }
        }
    }
}
