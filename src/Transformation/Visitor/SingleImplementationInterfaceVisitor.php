<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;
use Recombinator\Support\ExternalCode;

/**
 * Проверку по интерфейсу с единственной реализацией заменяет проверкой по классу:
 *
 *   interface StreamInterface {}  class Stream implements StreamInterface {}
 *   $x instanceof StreamInterface  →  $x instanceof Stream
 *   function f(StreamInterface $s) →  function f(Stream $s)
 *
 * Реализация единственная, если все классы программы, реализующие интерфейс
 * (напрямую, через предка или через расширяющий интерфейс), — один класс и его
 * наследники. Тогда для любого объекта программы проверки равносильны. Интерфейс
 * без реализаций: `$x instanceof I` для выражения без побочных эффектов → false.
 * Освободившийся интерфейс удаляет RemoveInterfacesVisitor.
 *
 * Заменяются instanceof, catch и типы параметров, свойств, возврата. Не трогаются
 * строки и `I::class` — по ним интерфейс останется.
 *
 * Не заменяется, если интерфейс реализует enum или анонимный класс, или его имя
 * встречается в implements/extends файла проекта, не вошедшего в программу (такой
 * класс подгрузит автозагрузка во время выполнения). Пока есть невстроенный include,
 * ничего не меняется.
 */
#[VisitorMeta('Интерфейс с единственной реализацией → класс в instanceof, catch и типах', targets: [Node\Stmt\Interface_::class])]
class SingleImplementationInterfaceVisitor extends BaseVisitor
{
    /** @var array<string, string|false> lowercase интерфейс → имя класса-реализации (false — реализаций нет) */
    private array $replacement = [];

    /** @param array<string, string> $fileMap файлы проекта (путь → код) */
    public function __construct(private readonly array $fileMap = [])
    {
    }

    #[\Override]
    public function beforeTraverse(array $nodes): ?array
    {
        parent::beforeTraverse($nodes);
        $this->replacement = [];
        if ($this->hasPendingIncludes()) {
            return null;
        }

        /** @var array<string, Node\Stmt\ClassLike> $declared */
        $declared   = [];
        $interfaces = [];
        $blocked    = [];
        foreach ($this->findNode(Node\Stmt\ClassLike::class) as $node) {
            if (!$node->name instanceof Node\Identifier) {
                // Анонимный класс: его интерфейсы не заменяются
                foreach ($node instanceof Node\Stmt\Class_ ? $node->implements : [] as $name) {
                    $blocked[strtolower(ltrim($name->toString(), '\\'))] = true;
                }

                continue;
            }

            $lower            = $node->name->toLowerString();
            $declared[$lower] = $node;
            if ($node instanceof Node\Stmt\Interface_) {
                $interfaces[$lower] = $node->name->toString();
            }

            if ($node instanceof Node\Stmt\Enum_) {
                foreach ($node->implements as $name) {
                    $blocked[strtolower(ltrim($name->toString(), '\\'))] = true;
                }
            }
        }

        if ($interfaces === []) {
            return null;
        }

        // Реализации: класс → все его интерфейсы (транзитивно)
        /** @var array<string, list<string>> $implementors интерфейс → классы */
        $implementors = [];
        foreach ($declared as $lower => $class) {
            if (!$class instanceof Node\Stmt\Class_) {
                continue;
            }

            foreach (array_keys($this->interfacesOf($class, $declared)) as $interface) {
                $implementors[$interface][] = $lower;
            }
        }

        $external = ExternalCode::forProgram($this->fileMap, $declared, $this->ast);
        foreach ($interfaces as $lower => $name) {
            if (isset($blocked[$lower]) || $external->extendsOrImplements($name)) {
                continue;
            }

            $classes = $implementors[$lower] ?? [];
            if ($classes === []) {
                $this->replacement[$lower] = false;
                continue;
            }

            // Корни: реализации, чей родитель интерфейс не реализует
            $roots = array_values(array_filter($classes, function (string $class) use ($classes, $declared): bool {
                $node   = $declared[$class];
                $parent = $node instanceof Node\Stmt\Class_ && $node->extends instanceof Node\Name ? strtolower(ltrim($node->extends->toString(), '\\')) : null;

                return $parent === null || !in_array($parent, $classes, true);
            }));
            if (count($roots) === 1) {
                $root                      = $declared[$roots[0]];
                $this->replacement[$lower] = $root->name instanceof Node\Identifier ? $root->name->toString() : false;
            }
        }

        return null;
    }

    public function enterNode(Node $node): ?Node
    {
        if ($this->replacement === []) {
            return null;
        }

        if ($node instanceof Node\Expr\Instanceof_ && $node->class instanceof Node\Name) {
            $lower = strtolower(ltrim($node->class->toString(), '\\'));
            if (!array_key_exists($lower, $this->replacement)) {
                return null;
            }

            $class = $this->replacement[$lower];
            if ($class === false) {
                return $this->isPure($node->expr) ? new Node\Expr\ConstFetch(new Node\Name('false'), $node->getAttributes()) : null;
            }

            $node->class = new Node\Name\FullyQualified($class, $node->class->getAttributes());

            return $node;
        }

        if ($node instanceof Node\Stmt\Catch_) {
            $node->types = array_map($this->replaceName(...), $node->types);

            return null;
        }

        if ($node instanceof Node\Param || $node instanceof Node\Stmt\Property) {
            $node->type = $this->replaceType($node->type);
        } elseif ($node instanceof Node\Stmt\ClassMethod || $node instanceof Node\Stmt\Function_ || $node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction) {
            $node->returnType = $this->replaceType($node->returnType);
        }

        return null;
    }

    private function replaceType(Node\ComplexType|Node\Identifier|Node\Name|null $type): Node\ComplexType|Node\Identifier|Node\Name|null
    {
        return match (true) {
            $type instanceof Node\Name => $this->replaceName($type),
            $type instanceof Node\NullableType => new Node\NullableType(
                $type->type instanceof Node\Name ? $this->replaceName($type->type) : $type->type,
                $type->getAttributes(),
            ),
            $type instanceof Node\UnionType, $type instanceof Node\IntersectionType => $this->replaceComplex($type),
            default => $type,
        };
    }

    private function replaceComplex(Node\UnionType|Node\IntersectionType $type): Node\UnionType|Node\IntersectionType
    {
        $parts = [];
        foreach ($type->types as $part) {
            $replaced = $this->replaceType($part);
            if ($replaced instanceof Node\Identifier || $replaced instanceof Node\Name || $replaced instanceof Node\IntersectionType) {
                $parts[] = $replaced;
            }
        }

        // Повтор одного класса в объединении — ошибка компиляции
        $unique = [];
        foreach ($parts as $part) {
            $unique[$part instanceof Node\Name ? strtolower($part->toString()) : spl_object_id($part)] = $part;
        }

        if (count($unique) !== count($parts)) {
            return $type;
        }

        $type->types = $parts;

        return $type;
    }

    /** Имя интерфейса без реализаций в типе оставляем: тип никогда не совпадёт, но это ошибка программы, а не наша. */
    private function replaceName(Node\Name $name): Node\Name
    {
        $class = $this->replacement[strtolower(ltrim($name->toString(), '\\'))] ?? false;

        return is_string($class) ? new Node\Name\FullyQualified($class, $name->getAttributes()) : $name;
    }

    private function isPure(Node\Expr $expr): bool
    {
        return match (true) {
            $expr instanceof Node\Expr\Variable => is_string($expr->name),
            $expr instanceof Node\Expr\PropertyFetch => $expr->name instanceof Node\Identifier && $this->isPure($expr->var),
            default => false,
        };
    }

    /**
     * Все интерфейсы класса (lowercase): свои, предков-классов и их предков-интерфейсов.
     *
     * @param  array<string, Node\Stmt\ClassLike> $declared
     * @return array<string, true>
     */
    private function interfacesOf(Node\Stmt\Class_ $class, array $declared): array
    {
        $result = [];
        $queue  = [...$class->implements];
        $seen   = [];
        $parent = $class;
        while ($parent instanceof Node\Stmt\Class_ && $parent->extends instanceof Node\Name) {
            $lower = strtolower(ltrim($parent->extends->toString(), '\\'));
            if (isset($seen['c' . $lower])) {
                break;
            }

            $seen['c' . $lower] = true;
            $parent             = $declared[$lower] ?? null;
            if ($parent instanceof Node\Stmt\Class_) {
                array_push($queue, ...$parent->implements);
            }
        }

        while ($queue !== []) {
            $lower = strtolower(ltrim(array_pop($queue)->toString(), '\\'));
            if (isset($result[$lower])) {
                continue;
            }

            $result[$lower] = true;
            $interface      = $declared[$lower] ?? null;
            if ($interface instanceof Node\Stmt\Interface_) {
                array_push($queue, ...$interface->extends);
            }
        }

        return $result;
    }
}
