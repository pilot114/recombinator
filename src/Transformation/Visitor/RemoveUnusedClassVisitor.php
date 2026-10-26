<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeVisitor;
use Recombinator\Core\Config;

/**
 * Удаляет классы (интерфейсы, трейты, enum), недостижимые из исполняемого кода.
 *
 * Достижимость транзитивна: корни — код верхнего уровня и функции; класс живой,
 * если живой код его загружает, и тогда живо всё его тело. Класс, на который
 * ссылается только мёртвый класс, тоже мёртв.
 *
 * Загружает класс: `new`, `::` (вызов, константа, статическое свойство), extends,
 * implements, use трейта, атрибут (Doctrine создаёт их через рефлексию). Тип
 * параметра, `instanceof` и `catch` класс не загружают: если объектов класса нет,
 * проверка ложна и без объявления. Имя строкой (`'App_User'`, `X::class`, callable
 * `'X::m'`) считается ссылкой: из строки делают `new $cls` и ключи контейнера.
 *
 * Не видны имена, собранные из частей (`'App_' . $name`), и ссылки из файлов, которые
 * не попали в AST. После встраивания классы переименованы (`Foo\Bar` → `Foo_Bar`),
 * поэтому внешний файл всё равно не может на них сослаться.
 */
#[VisitorMeta('Удаление классов, недостижимых из исполняемого кода')]
class RemoveUnusedClassVisitor extends BaseVisitor
{
    /** @var \SplObjectStorage<Node\Stmt\ClassLike, true> */
    private \SplObjectStorage $dead;

    private readonly Config $config;

    public function __construct(?Config $config = null)
    {
        $this->config = $config ?? new Config();
        $this->dead   = new \SplObjectStorage();
    }

    #[\Override]
    public function beforeTraverse(array $nodes): ?array
    {
        parent::beforeTraverse($nodes);
        $this->dead = new \SplObjectStorage();

        /** @var array<string, list<Node\Stmt\ClassLike>> $classes lowercase имя → объявления */
        $classes = [];
        $roots   = [];
        foreach ($nodes as $node) {
            foreach ($node instanceof Node\Stmt\Namespace_ ? $node->stmts : [$node] as $stmt) {
                if ($stmt instanceof Node\Stmt\ClassLike && $stmt->name instanceof Node\Identifier) {
                    $classes[strtolower($stmt->name->toString())][] = $stmt;
                } else {
                    $roots[] = $stmt;
                }
            }
        }

        if ($classes === []) {
            return null;
        }

        $live  = [];
        $queue = array_keys($this->referencedNames($roots));
        foreach (array_keys($classes) as $name) {
            if ($this->config->isProtected($name)) {
                $queue[] = $name;
            }
        }

        while ($queue !== []) {
            $name = array_pop($queue);
            if (isset($live[$name]) || !isset($classes[$name])) {
                continue;
            }

            $live[$name] = true;
            foreach (array_keys($this->referencedNames($classes[$name])) as $ref) {
                if (!isset($live[$ref])) {
                    $queue[] = $ref;
                }
            }
        }

        foreach ($classes as $name => $decls) {
            if (!isset($live[$name])) {
                foreach ($decls as $decl) {
                    $this->dead[$decl] = true;
                }
            }
        }

        return null;
    }

    public function enterNode(Node $node): ?int
    {
        // Классы объявляются на верхнем уровне или в namespace — глубже не спускаемся
        if ($node instanceof Node\Stmt\ClassLike && isset($this->dead[$node])) {
            $node->setAttribute('remove', true);
        }

        return $node instanceof Node\Stmt\Namespace_ ? null : NodeVisitor::DONT_TRAVERSE_CHILDREN;
    }

    /**
     * Имена классов (lowercase), которые загружает код $nodes.
     *
     * @param  array<Node> $nodes
     * @return array<string, true>
     */
    private function referencedNames(array $nodes): array
    {
        $names = [];
        $add   = static function (?Node $name) use (&$names): void {
            if ($name instanceof Node\Name) {
                $names[strtolower(ltrim($name->toString(), '\\'))] = true;
            }
        };

        foreach (new NodeFinder()->find($nodes, static fn(Node $n): bool => true) as $node) {
            match (true) {
                $node instanceof Node\Expr\New_, $node instanceof Node\Expr\StaticCall,
                $node instanceof Node\Expr\ClassConstFetch, $node instanceof Node\Expr\StaticPropertyFetch => $add($node->class),
                $node instanceof Node\Stmt\Class_ => array_map($add, [$node->extends, ...$node->implements]),
                $node instanceof Node\Stmt\Interface_ => array_map($add, $node->extends),
                $node instanceof Node\Stmt\Enum_ => array_map($add, $node->implements),
                $node instanceof Node\Stmt\TraitUse => array_map($add, $node->traits),
                $node instanceof Node\Attribute => $add($node->name),
                $node instanceof Node\Scalar\String_ => $names[strtolower(ltrim(explode('::', $node->value, 2)[0], '\\'))] = true,
                default => null,
            };
        }

        return $names;
    }
}
