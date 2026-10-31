<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeVisitor;
use Recombinator\Core\Config;
use Recombinator\Support\StringValues;

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
 * `'X::m'`) считается ссылкой: из строки делают `new $cls` и ключи контейнера —
 * кроме ключей элементов массива и второго аргумента is_a() (см. onlyLabel).
 *
 * Функции, вынесенные из статических методов (StaticMethodExtractVisitor: атрибут
 * extractedFrom или имя `<класс программы>_<метод>`), — такие же вершины графа, а не
 * корни: живы, если живой код вызывает их по имени или упоминает строкой. Иначе метод
 * мёртвого класса, вынесенный в функцию, держал бы класс живым (`isSupported()` →
 * `PhpFilesAdapter::$startTime`). Мёртвые вынесенные функции удаляются вместе с классами;
 * внешний код вызвать их не может — имя плоское, его нет в исходниках.
 *
 * Не видны имена, собранные из частей (`'App_' . $name`), и ссылки из файлов, которые
 * не попали в AST. После встраивания классы переименованы (`Foo\Bar` → `Foo_Bar`),
 * поэтому внешний файл всё равно не может на них сослаться.
 */
#[VisitorMeta('Удаление классов, недостижимых из исполняемого кода', targets: [Node\Stmt\ClassLike::class, Node\Stmt\Function_::class])]
class RemoveUnusedClassVisitor extends BaseVisitor
{
    /** @var \SplObjectStorage<Node\Stmt\ClassLike|Node\Stmt\Function_, true> */
    private \SplObjectStorage $dead;

    private readonly Config $config;

    /** @var \SplObjectStorage<Node\Expr, list<string>> выражение имени класса → возможные плоские имена */
    private \SplObjectStorage $computed;

    public function __construct(?Config $config = null)
    {
        $this->config   = $config ?? new Config();
        $this->dead     = new \SplObjectStorage();
        $this->computed = new \SplObjectStorage();
    }

    #[\Override]
    public function beforeTraverse(array $nodes): ?array
    {
        parent::beforeTraverse($nodes);
        $this->dead = new \SplObjectStorage();

        /** @var array<string, list<Node\Stmt\ClassLike|Node\Stmt\Function_>> $classes lowercase имя (функции — `fn:имя`) → объявления */
        $classes   = [];
        $roots     = [];
        $functions = [];
        foreach ($nodes as $node) {
            foreach ($node instanceof Node\Stmt\Namespace_ ? $node->stmts : [$node] as $stmt) {
                if ($stmt instanceof Node\Stmt\ClassLike && $stmt->name instanceof Node\Identifier) {
                    $classes[strtolower($stmt->name->toString())][] = $stmt;
                } elseif ($stmt instanceof Node\Stmt\Function_ && $node === $stmt) {
                    $functions[] = $stmt;
                } else {
                    $roots[] = $stmt;
                }
            }
        }

        foreach ($functions as $function) {
            if ($this->isExtracted($function, $classes)) {
                $classes['fn:' . $function->name->toLowerString()][] = $function;
            } else {
                $roots[] = $function;
            }
        }

        if ($classes === []) {
            return null;
        }

        // Имена, собранные во время выполнения (`new (sprintf('Faker\Provider\%s', $p))`):
        // значения известны конечным набором — классы с ними загружаются из кода сайта
        $this->computed = new \SplObjectStorage();
        $values         = new StringValues($nodes);
        foreach (StringValues::classNameSites($nodes) as $site) {
            $this->computed[$site] = array_map(
                static fn(string $v): string => strtolower(strtr(ltrim($v, '\\'), ['\\' => '_'])),
                $values->of($site) ?? [],
            );
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
        if (($node instanceof Node\Stmt\ClassLike || $node instanceof Node\Stmt\Function_) && isset($this->dead[$node])) {
            $node->setAttribute('remove', true);
        }

        return $node instanceof Node\Stmt\Namespace_ ? null : NodeVisitor::DONT_TRAVERSE_CHILDREN;
    }

    /**
     * Функция вынесена из статического метода класса программы.
     *
     * @param array<string, list<Node\Stmt\ClassLike|Node\Stmt\Function_>> $classes
     */
    private function isExtracted(Node\Stmt\Function_ $function, array $classes): bool
    {
        if ($function->getAttribute('extractedFrom') !== null) {
            return true;
        }

        $name = $function->name->toLowerString();
        for ($at = strrpos($name, '_'); $at !== false && $at > 0; $at = strrpos(substr($name, 0, $at), '_')) {
            if (isset($classes[substr($name, 0, $at)])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Строка-метка, по которой класс не создают и не загружают: ключ элемента массива
     * (`['OraclePlatform' => 2][$platform::class]` — карта по имени класса; создают по
     * значениям карт, как DRIVER_MAP) и имя во втором аргументе is_a()/is_subclass_of()
     * (проверка объекта; без класса она ложна).
     */
    public static function onlyLabel(Node\Scalar\String_ $string): bool
    {
        $parent = $string->getAttribute('parent');
        if ($parent instanceof Node\ArrayItem && $parent->key === $string || $parent instanceof Node\Expr\ArrayDimFetch && $parent->dim === $string) {
            return true;
        }

        $call = $parent instanceof Node\Arg ? $parent->getAttribute('parent') : null;

        return $call instanceof Node\Expr\FuncCall && $call->name instanceof Node\Name
            && in_array($call->name->toLowerString(), ['is_a', 'is_subclass_of'], true)
            && ($call->args[1] ?? null) === $parent
            && (!($call->args[0] ?? null) instanceof Node\Arg || !$call->args[0]->value instanceof Node\Scalar\String_);
    }

    /**
     * Имена в позициях, где PHP загружает класс: `new`, `::`, extends, implements, use
     * трейта, атрибут. Тип параметра, `instanceof` и `catch` класс не загружают.
     * Общее правило с UseImportVisitor: встроенный им класс, который здесь не загружается,
     * удалялся бы снова, и правила сменяли бы друг друга без конца.
     *
     * @param  array<Node> $nodes
     * @return \SplObjectStorage<Node\Name, true>
     */
    public static function loadingNames(array $nodes): \SplObjectStorage
    {
        $names = new \SplObjectStorage();
        foreach (new NodeFinder()->find($nodes, static fn(Node $n): bool => true) as $node) {
            $found = match (true) {
                $node instanceof Node\Expr\New_, $node instanceof Node\Expr\StaticCall,
                $node instanceof Node\Expr\ClassConstFetch, $node instanceof Node\Expr\StaticPropertyFetch => [$node->class],
                $node instanceof Node\Stmt\Class_ => [$node->extends, ...$node->implements],
                $node instanceof Node\Stmt\Interface_ => $node->extends,
                $node instanceof Node\Stmt\Enum_ => $node->implements,
                $node instanceof Node\Stmt\TraitUse => $node->traits,
                $node instanceof Node\Attribute => [$node->name],
                default => [],
            };
            foreach ($found as $name) {
                if ($name instanceof Node\Name) {
                    $names[$name] = true;
                }
            }
        }

        return $names;
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
        foreach (self::loadingNames($nodes) as $name) {
            $names[strtolower(ltrim($name->toString(), '\\'))] = true;
        }

        foreach (new NodeFinder()->find($nodes, static fn(Node $n): bool => true) as $node) {
            if ($node instanceof Node\Expr && isset($this->computed[$node])) {
                foreach ($this->computed[$node] as $flat) {
                    $names[$flat] = true;
                }
            }

            match (true) {
                $node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name => $names['fn:' . strtolower(ltrim($node->name->toString(), '\\'))] = true,
                $node instanceof Node\Scalar\String_ && !self::onlyLabel($node) => $names += [
                    strtolower(ltrim(explode('::', $node->value, 2)[0], '\\')) => true,
                    'fn:' . strtolower(ltrim($node->value, '\\'))           => true,
                ],
                default => null,
            };
        }

        return $names;
    }
}
