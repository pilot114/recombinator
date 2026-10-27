<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;
use PhpParser\NodeFinder;
use Recombinator\Core\Config;

/**
 * Удаляет методы, имя которых не встречается в живом коде.
 *
 * Анализ по имени, без типа получателя: метод `m` живой, если в живом коде есть
 * вызов `->m()`, `::m()`, `m(...)` или строка `'m'` (`[$obj, 'm']`, `'C::m'`, нотация
 * Slim `'C:m'`, `method_exists($x, 'm')`). Живой код — верхний уровень, функции, всё
 * в классах, кроме тел методов, и тела живых методов (транзитивно). Строка, собранная
 * из частей с литеральным началом (`'get' . $x`, `"get{$x}"`), оживляет все методы
 * с этим префиксом.
 *
 * Остаются всегда: магические методы (`__construct`, `__invoke`, `__call`, …),
 * методы с атрибутами (их находят рефлексией: колбэки жизненного цикла Doctrine),
 * методы, которые объявляет внешний предок (`getIterator`, `offsetGet`, …) или
 * предок, которого нет в программе, методы классов публичного контракта.
 *
 * Как и в RemoveUnusedClassVisitor, не видны имена, вычисленные иначе (из данных,
 * `ucfirst($x)`), и вызовы из невстраиваемых include — те же допущения, что и для классов.
 */
#[VisitorMeta('Удаление методов, имя которых не используется в живом коде')]
class RemoveUnusedMethodVisitor extends BaseVisitor
{
    /** @var \SplObjectStorage<Node\Stmt\ClassMethod, true> */
    private \SplObjectStorage $dead;

    private readonly Config $config;

    /**
     * @param array<string, string> $fileMap файлы проекта (путь → код): файлы, чьи классы не
     *                                       вошли в программу, загрузятся автозагрузкой во время
     *                                       выполнения и могут вызвать методы программы (провайдеры
     *                                       Faker наследуют встроенный `Faker\Provider\Base`)
     */
    public function __construct(?Config $config = null, private readonly array $fileMap = [])
    {
        $this->config = $config ?? new Config();
        $this->dead   = new \SplObjectStorage();
    }

    #[\Override]
    public function beforeTraverse(array $nodes): ?array
    {
        parent::beforeTraverse($nodes);
        $this->dead = new \SplObjectStorage();
        // Ждём встраивания подключаемых файлов; невстраиваемые (данные, прокси Doctrine)
        // не учитываются — то же соглашение, что у StaticMethodExtractVisitor
        if ($this->hasPendingIncludes()) {
            return null;
        }

        /** @var array<string, Node\Stmt\ClassLike> $classes */
        $classes = [];
        foreach ($this->findNode(Node\Stmt\ClassLike::class) as $class) {
            if ($class->name instanceof Node\Identifier) {
                $classes[$class->name->toLowerString()] = $class;
            }
        }

        // Кандидаты: обычные методы; остальные методы и всё прочее — корни
        /** @var array<string, list<Node\Stmt\ClassMethod>> $methods */
        $methods = [];
        $roots   = [];
        $this->partition($nodes, $classes, $methods, $roots);

        $live     = [];
        $prefixes = [];
        $queue    = [];
        $this->collectNames($roots, $live, $prefixes, $queue);
        foreach (array_keys($this->externalNames($classes)) as $name) {
            if (!isset($live[$name])) {
                $live[$name] = true;
                $queue[]     = (string) $name;
            }
        }

        do {
            while ($queue !== []) {
                $name = array_pop($queue);
                foreach ($methods[$name] ?? [] as $method) {
                    $this->collectNames($method->stmts ?? [], $live, $prefixes, $queue);
                }
            }

            // Префиксы оживляют сразу все подходящие методы
            foreach (array_keys($methods) as $candidate) {
                if (!isset($live[$candidate]) && $this->matchesPrefix((string) $candidate, $prefixes)) {
                    $live[$candidate] = true;
                    $queue[]          = (string) $candidate;
                }
            }
        } while ($queue !== []);

        foreach ($methods as $name => $declarations) {
            if (isset($live[$name])) {
                continue;
            }

            foreach ($declarations as $method) {
                $this->dead[$method] = true;
            }
        }

        return null;
    }

    public function enterNode(Node $node): ?Node
    {
        if ($node instanceof Node\Stmt\ClassMethod && isset($this->dead[$node])) {
            $node->setAttribute('remove', true);
        }

        return null;
    }

    /**
     * Делит программу на кандидатов в удаление (по lowercase имени) и корни.
     *
     * @param array<Node>                                 $nodes
     * @param array<string, Node\Stmt\ClassLike>          $classes
     * @param array<string, list<Node\Stmt\ClassMethod>>  $methods
     * @param list<Node>                                  $roots
     */
    private function partition(array $nodes, array $classes, array &$methods, array &$roots): void
    {
        $finder = new NodeFinder();
        $inside = $finder->findInstanceOf($nodes, Node\Stmt\ClassLike::class);
        foreach ($inside as $class) {
            $protected = $class->name instanceof Node\Identifier && $this->config->isProtected($class->name->toString());
            foreach ($class->stmts as $stmt) {
                if (!$stmt instanceof Node\Stmt\ClassMethod) {
                    $roots[] = $stmt;
                    continue;
                }

                if ($protected || $this->pinned($stmt, $class, $classes)) {
                    $roots[] = $stmt;
                    continue;
                }

                $methods[$stmt->name->toLowerString()][] = $stmt;
            }
        }

        // Код вне классов; тела классов разобраны выше (вложенные в функции классы — тоже)
        foreach ($nodes as $node) {
            foreach ($node instanceof Node\Stmt\Namespace_ ? $node->stmts : [$node] as $stmt) {
                if (!$stmt instanceof Node\Stmt\ClassLike) {
                    $roots[] = $stmt;
                }
            }
        }
    }

    /**
     * Метод, который нельзя удалить при любом использовании имени.
     *
     * @param array<string, Node\Stmt\ClassLike> $classes
     */
    private function pinned(Node\Stmt\ClassMethod $method, Node\Stmt\ClassLike $class, array $classes): bool
    {
        if (str_starts_with($method->name->toString(), '__') || $method->attrGroups !== []) {
            return true;
        }

        return $this->declaredOutside($class, $method->name->toString(), $classes, 0);
    }

    /**
     * Объявлен ли метод у внешнего предка (или предок неизвестен).
     *
     * @param array<string, Node\Stmt\ClassLike> $classes
     */
    private function declaredOutside(Node\Stmt\ClassLike $class, string $method, array $classes, int $depth): bool
    {
        if ($depth > 32) {
            return true;
        }

        $parents = match (true) {
            $class instanceof Node\Stmt\Class_ => [$class->extends, ...$class->implements],
            $class instanceof Node\Stmt\Interface_ => $class->extends,
            $class instanceof Node\Stmt\Enum_ => $class->implements,
            default => [],
        };
        if ($class instanceof Node\Stmt\Enum_ && in_array(strtolower($method), ['cases', 'from', 'tryfrom'], true)) {
            return true;
        }

        foreach ($parents as $parent) {
            if (!$parent instanceof Node\Name) {
                continue;
            }

            $name     = strtolower(ltrim($parent->toString(), '\\'));
            $declared = $classes[$name] ?? null;
            if ($declared instanceof Node\Stmt\ClassLike) {
                if ($this->declaredOutside($declared, $method, $classes, $depth + 1)) {
                    return true;
                }

                continue;
            }

            if (!class_exists($name) && !interface_exists($name)) {
                return true;
            }

            if (new \ReflectionClass($name)->hasMethod($method)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Имена методов (lowercase), упомянутые в коде, и префиксы составных строк.
     *
     * @param array<Node>          $nodes
     * @param array<string, true>  $live
     * @param array<string, true>  $prefixes
     * @param list<string>         $queue
     */
    private function collectNames(array $nodes, array &$live, array &$prefixes, array &$queue): void
    {
        $add = static function (string $name) use (&$live, &$queue): void {
            $name = strtolower($name);
            if ($name !== '' && !isset($live[$name])) {
                $live[$name] = true;
                $queue[]     = $name;
            }
        };

        foreach (new NodeFinder()->find($nodes, static fn(Node $n): bool => true) as $node) {
            if (
                ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\NullsafeMethodCall || $node instanceof Node\Expr\StaticCall)
                && $node->name instanceof Node\Identifier
            ) {
                $add($node->name->toString());
            } elseif ($node instanceof Node\Scalar\String_) {
                // 'm', 'C::m', 'C:m' (нотация Slim)
                $value = $node->value;
                $add($value);
                $tail = strrchr($value, ':');
                if ($tail !== false) {
                    $add(substr($tail, 1));
                }
            } elseif ($node instanceof Node\Scalar\InterpolatedString) {
                $first = $node->parts[0] ?? null;
                if ($first instanceof Node\InterpolatedStringPart) {
                    $this->addPrefix($first->value, $prefixes);
                }
            } elseif ($node instanceof Node\Expr\BinaryOp\Concat) {
                // Учитывается только литеральное начало: имя, собранное с конца
                // (`$x . 'Suffix'`), не видно — то же допущение, что и для классов
                $left = $node->left;
                while ($left instanceof Node\Expr\BinaryOp\Concat) {
                    $left = $left->left;
                }

                if ($left instanceof Node\Scalar\String_) {
                    $this->addPrefix($left->value, $prefixes);
                }
            } elseif ($node instanceof Node\Stmt\TraitUseAdaptation\Alias) {
                $add($node->method->toString());
            }
        }
    }

    /**
     * Имена методов, которые упоминает код вне программы: файлы проекта, чьи классы
     * в программу не вошли (и файлы без классов). Разбор по токенам: `->m`, `?->m`,
     * `::m`, строковые литералы.
     *
     * @param  array<string, Node\Stmt\ClassLike> $classes
     * @return array<string, true>
     */
    private function externalNames(array $classes): array
    {
        $names = [];
        foreach ($this->fileMap as $code) {
            if ($this->isInternal($code, $classes)) {
                continue;
            }

            $tokens = token_get_all($code);
            $prev   = null;
            foreach ($tokens as $token) {
                if (!is_array($token)) {
                    $prev = $token;
                    continue;
                }

                if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                $afterAccess = is_array($prev) && in_array($prev[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true);
                if ($token[0] === T_STRING && $afterAccess) {
                    $names[strtolower($token[1])] = true;
                } elseif ($token[0] === T_CONSTANT_ENCAPSED_STRING) {
                    $value = strtolower(substr($token[1], 1, -1));
                    $names[$value] = true;
                    $tail          = strrchr($value, ':');
                    if ($tail !== false) {
                        $names[substr($tail, 1)] = true;
                    }
                }

                $prev = $token;
            }
        }

        return $names;
    }

    /**
     * Файл вошёл в программу: хотя бы один объявленный в нём класс есть в AST
     * под плоским именем (`Foo\Bar` → `Foo_Bar`).
     *
     * @param array<string, Node\Stmt\ClassLike> $classes
     */
    private function isInternal(string $code, array $classes): bool
    {
        $namespace = preg_match('/^\s*namespace\s+([\w\\\\]+)\s*[;{]/m', $code, $match) === 1 ? $match[1] . '\\' : '';
        if (preg_match_all('/^\s*(?:(?:final|abstract|readonly)\s+)*(?:class|interface|trait|enum)\s+(\w+)/m', $code, $matches) < 1) {
            return false;
        }

        return array_any($matches[1], fn(string $short): bool => isset($classes[strtolower(str_replace('\\', '_', $namespace . $short))]));
    }

    /**
     * Начало имени метода: только то, что может начинать идентификатор
     * (`'SELECT ' . $x` методом стать не может).
     *
     * @param array<string, true> $prefixes
     */
    private function addPrefix(string $value, array &$prefixes): void
    {
        if (preg_match('/^[a-z_\x80-\xff][a-z0-9_\x80-\xff]*$/i', $value) === 1) {
            $prefixes[strtolower($value)] = true;
        }
    }

    /** @param array<string, true> $prefixes */
    private function matchesPrefix(string $name, array $prefixes): bool
    {
        for ($length = strlen($name); $length > 0; $length--) {
            if (isset($prefixes[substr($name, 0, $length)])) {
                return true;
            }
        }

        return false;
    }
}
