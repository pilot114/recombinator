<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

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

            $this->flatten($child, $parent);
        }

        return null;
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

        $copied = [];

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
            $copied[] = $this->buildParentCopy($stmt, $childProps, $copied);
        }

        $child->stmts = array_merge($copied, $child->stmts);
        $this->rewriteParentCalls($child, $parentCalls, $parentStatic);
        $this->mergeImplements($child, $parent);
        $child->extends = null;
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
        array &$childProps,
        array &$copied,
    ): Node\Stmt\ClassMethod {
        $copy = $this->deepClone($method);
        $copy->name = new Node\Identifier('__parent_' . $method->name->name);

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
     * @param array<string, true> $targets
     * @param array<string, bool> $staticMap
     */
    private function rewriteParentCalls(Node\Stmt\Class_ $child, array $targets, array $staticMap): void
    {
        if ($targets === []) {
            return;
        }

        $rewriter = new class ($targets, $staticMap) extends NodeVisitorAbstract {
            /**
             * @param array<string, true> $targets
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

                $synthetic = new Node\Identifier('__parent_' . $name);
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
            $duplicate = array_any($child->implements, fn($existing): bool => $existing->toString() === $name);
            if (!$duplicate) {
                $child->implements[] = $impl;
            }
        }
    }
}
