<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * Заменяет `use Foo\Bar` определением класса из карты файлов.
 *
 * Для каждого use-оператора (type = normal, т.е. классы/интерфейсы/трейты)
 * ищет файл в fileMap по суффиксу пути (Bar.php, Foo/Bar.php, …),
 * парсит его и вставляет namespace-блок с объявлением класса вместо use.
 *
 * Повторный импорт одного и того же FQCN пропускается.
 *
 * Строковые литералы с полным именем класса (`'Nyholm\Psr7\Factory\Psr17Factory'`)
 * переименовываются так же, как ссылки на класс: иначе `class_exists($s)`,
 * `new $s` и ключи контейнера указывают на класс, которого после встраивания нет.
 * Класс, названный только строкой, тоже встраивается — если его файл есть в карте.
 */
#[VisitorMeta('Импорт классов по use-декларациям')]
class UseImportVisitor extends BaseVisitor
{
    /**
     * FQCNs, успешно инлайненных или находящихся в процессе инлайнинга.
     * Устанавливается ДО рекурсии — разрывает циклические зависимости.
     *
     * @var array<string, true>
     */
    private array $inlined = [];

    /**
     * FQCNs, которые искались, но не найдены в fileMap (внешние зависимости).
     *
     * @var array<string, true>
     */
    private array $notInFileMap = [];

    /**
     * Ключи fileMap, чей файл сейчас обрабатывается (in-progress).
     * Предотвращает ложное совпадение: если файл 'src/Kernel.php' уже открыт
     * для App\Kernel, другой FQCN (Symfony\...\Kernel) не должен брать тот же файл.
     *
     * @var array<string, true>
     */
    private array $filesInProgress = [];

    /**
     * Карта алиасов из use-деклараций обрабатываемого (входного) кода:
     * lowercase короткое имя → плоское FQCN-имя (например 'app' → 'Slim_App').
     * Инлайненные файлы переименовываются своими собственными картами
     * (см. expandInlinedUses): у каждого файла свои use.
     *
     * @var array<string, string>
     */
    private array $aliasMap = [];

    /**
     * Индекс для быстрого поиска файлов: суффикс пути → список ключей fileMap.
     * Позволяет искать O(1) вместо O(n) по всем файлам.
     *
     * @var array<string, list<string>>
     */
    private array $fileIndex = [];

    /**
     * Кэш разобранных AST: ключ fileMap → массив стейтментов (null = ошибка парсинга).
     *
     * @var array<string, array<Node\Stmt>|null>
     */
    private array $parseCache = [];

    /** @var array<string, array<string, list<Node\Name>>> область use → имена (см. qualifiedNames) */
    private array $qualifiedCache = [];

    /**
     * Классы, уже объявленные в AST (lowercase плоское имя): на повторных проходах
     * их не встраиваем второй раз.
     *
     * @var array<string, true>
     */
    private array $declared = [];

    /** В этом обходе встроен файл без declare(strict_types=1) */
    private bool $nonStrictInlined = false;

    private const string FQCN_PATTERN = '/^\\\\?[A-Za-z_\x80-\xff][\w\x80-\xff]*(?:\\\\[A-Za-z_\x80-\xff][\w\x80-\xff]*)+$/';

    private readonly Parser $parser;

    /** @param array<string, string> $fileMap relative-path → source code */
    public function __construct(private readonly array $fileMap)
    {
        $this->parser = new ParserFactory()->createForNewestSupportedVersion();
    }

    #[\Override]
    public function beforeTraverse(array $nodes): ?array
    {
        parent::beforeTraverse($nodes);
        $this->inlined          = [];
        $this->notInFileMap     = [];
        $this->filesInProgress  = [];
        $this->aliasMap         = [];
        $this->parseCache       = [];
        $this->qualifiedCache   = [];
        $this->declared         = [];
        $this->nonStrictInlined = false;
        $changed                = false;
        foreach ($nodes as $node) {
            foreach ($node instanceof Node\Stmt\Namespace_ ? $node->stmts : [$node] as $stmt) {
                if ($stmt instanceof Node\Stmt\ClassLike && $stmt->name instanceof Node\Identifier) {
                    $this->declared[strtolower($stmt->name->toString())] = true;
                }

                $changed = $changed || $stmt instanceof Node\Stmt\GroupUse;
            }
        }

        if ($changed) {
            $nodes = $this->expandGroupUses($nodes);
            foreach ($nodes as $node) {
                if ($node instanceof Node\Stmt\Namespace_) {
                    $node->stmts = $this->expandGroupUses($node->stmts);
                    foreach ($node->stmts as $stmt) {
                        $stmt->setAttribute('parent', $node);
                    }
                }
            }

            $this->ast = $nodes;
        }

        $this->buildFileIndex();
        return $changed ? $nodes : null;
    }

    /** Ключ карты алиасов для `use function` / `use const` (у классов — просто имя). */
    public static function useKey(int $type, string $alias): string
    {
        return $type === Node\Stmt\Use_::TYPE_FUNCTION ? 'f:' . strtolower($alias) : 'c:' . $alias;
    }

    public static function symbolKey(Node\Expr\FuncCall|Node\Expr\ConstFetch $node, string $name): string
    {
        return self::useKey($node instanceof Node\Expr\FuncCall ? Node\Stmt\Use_::TYPE_FUNCTION : Node\Stmt\Use_::TYPE_CONSTANT, $name);
    }

    /**
     * Есть ли в начале файла declare(strict_types=1).
     *
     * @param array<Node> $ast
     */
    public static function isStrictFile(array $ast): bool
    {
        foreach ($ast as $node) {
            if ($node instanceof Node\Stmt\Declare_) {
                foreach ($node->declares as $declare) {
                    if ($declare->key->toString() === 'strict_types' && $declare->value instanceof Node\Scalar\Int_ && $declare->value->value === 1) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * strict_types действует на свой файл: после встраивания нестрогого файла
     * (`version_compare(false, ...)` в Symfony приводит false к строке) строгий
     * режим входного файла распространился бы на чужой код и превратил бы
     * приведение в TypeError. Для корректной строгой программы снятие режима
     * ничего не меняет.
     *
     * @param  array<Node> $nodes
     * @return array<Node>|null null — снимать нечего
     */
    public static function withoutStrictTypes(array $nodes): ?array
    {
        $result = array_values(array_filter(
            $nodes,
            static fn(Node $node): bool => !$node instanceof Node\Stmt\Declare_
                || $node->stmts !== null
                || !array_any($node->declares, static fn(Node\DeclareItem $d): bool => $d->key->toString() === 'strict_types'),
        ));

        return count($result) === count($nodes) ? null : $result;
    }

    /**
     * `use A\{B, C as D};` → `use A\B; use A\C as D;`: дальше работаем только с Use_.
     *
     * @template T of Node
     * @param  array<T> $stmts
     * @return list<T|Node\Stmt\Use_>
     */
    private function expandGroupUses(array $stmts): array
    {
        $result = [];
        foreach ($stmts as $stmt) {
            if (!$stmt instanceof Node\Stmt\GroupUse) {
                $result[] = $stmt;
                continue;
            }

            foreach ($stmt->uses as $item) {
                $type     = $item->type !== Node\Stmt\Use_::TYPE_UNKNOWN ? $item->type : $stmt->type;
                $result[] = new Node\Stmt\Use_(
                    [new Node\UseItem(Node\Name::concat($stmt->prefix, $item->name) ?? $item->name, $item->alias)],
                    $type,
                );
            }
        }

        return $result;
    }

    /**
     * Переименовывает строковые литералы и полные имена (`extends \Doctrine\DBAL\Configuration`)
     * встроенных классов и встраивает классы, на которые ссылаются только так.
     */
    #[\Override]
    public function afterTraverse(array $nodes): ?array
    {
        if ($this->nonStrictInlined) {
            $nodes = self::withoutStrictTypes($nodes) ?? $nodes;
        }

        $pulled  = [];
        $canPull = !array_any($nodes, static fn(Node $n): bool => $n instanceof Node\Stmt\Namespace_);
        $finder  = new NodeFinder();

        // Функции с namespace, встроенные FileMapIncludeVisitor как `Ns_func`
        $functions = [];
        foreach ($finder->findInstanceOf($nodes, Node\Stmt\Function_::class) as $function) {
            $functions[strtolower($function->name->toString())] = true;
        }

        $notClass = new \SplObjectStorage();
        $refs     = [];
        $candidates = $finder->find(
            $nodes,
            static fn(Node $n): bool => $n instanceof Node\Scalar\String_ || $n instanceof Node\Name\FullyQualified
                || $n instanceof Node\Expr\FuncCall || $n instanceof Node\Expr\ConstFetch,
        );
        foreach ($candidates as $n) {
            if ($n instanceof Node\Expr\FuncCall && $n->name instanceof Node\Name\FullyQualified) {
                $flat = strtr($n->name->toString(), ['\\' => '_']);
                if (count($n->name->getParts()) > 1 && isset($functions[strtolower($flat)])) {
                    $n->name->name = $flat;
                }
            }

            $flatString = $n instanceof Node\Scalar\String_ ? strtr(ltrim($n->value, '\\'), ['\\' => '_']) : '';
            if ($n instanceof Node\Scalar\String_ && str_contains($n->value, '\\') && isset($functions[strtolower($flatString)])) {
                $n->value = $flatString;
                continue;
            }

            if ($n instanceof Node\Expr\FuncCall || $n instanceof Node\Expr\ConstFetch) {
                $notClass[$n->name] = true;
            } elseif ($n instanceof Node\Name\FullyQualified && count($n->getParts()) > 1) {
                $refs[] = $n;
            } elseif (
                $n instanceof Node\Scalar\String_
                && str_contains($n->value, '\\')
                && preg_match(self::FQCN_PATTERN, explode('::', $n->value, 2)[0]) === 1
            ) {
                // 'Doctrine\ORM\Mapping\ClassMetadata::GENERATOR_TYPE_' — имя класса перед ::
                $refs[] = $n;
            }
        }

        foreach ($refs as $ref) {
            if ($ref instanceof Node\Name && isset($notClass[$ref])) {
                continue;
            }

            [$classPart, $member] = array_pad(explode('::', $ref instanceof Node\Name ? $ref->toString() : $ref->value, 2), 2, null);
            $fqcn = ltrim((string) $classPart, '\\');
            $flat = strtr($fqcn, ['\\' => '_']);
            if (!isset($this->declared[strtolower($flat)]) && !isset($this->inlined[$fqcn])) {
                if (!$canPull) {
                    continue;
                }

                $resolved = $this->resolveClass($fqcn);
                if ($resolved === null) {
                    continue;
                }

                array_push($pulled, ...$resolved);
            }

            if ($ref instanceof Node\Name && $flat !== '') {
                $ref->name = $flat;
            } elseif ($ref instanceof Node\Scalar\String_) {
                $ref->value = $member !== null ? $flat . '::' . $member : $flat;
            }
        }

        if ($pulled === []) {
            $ordered = $canPull ? $this->orderDeclarations($nodes) : null;

            return $ordered ?? ($this->nonStrictInlined ? $nodes : null);
        }

        // Перед первым исполняемым оператором: зависимости, встроенные по use, уже объявлены выше
        $at = 0;
        while (isset($nodes[$at]) && $this->isDeclaration($nodes[$at])) {
            $at++;
        }

        array_splice($nodes, $at, 0, $pulled);

        return $this->orderDeclarations($nodes) ?? $nodes;
    }

    /**
     * Родитель, интерфейсы и трейты должны быть объявлены раньше класса: иначе PHP
     * не свяжет класс (`Class "AbstractUnicodeString" not found`). Объявления верхнего
     * уровня переставляются топологически и собираются на месте первого из них.
     *
     * @param  array<Node> $nodes
     * @return array<Node>|null null — порядок уже верный
     */
    private function orderDeclarations(array $nodes): ?array
    {
        // Объявление — класс верхнего уровня или if, в ветках которого только классы
        // (`if (PHP_VERSION_ID >= 70400) { trait T {...} } else { trait T {...} }`)
        /** @var array<int, list<Node\Stmt\ClassLike>> $declared id узла → объявленные в нём классы */
        $declared = [];
        /** @var array<string, Node> $byName */
        $byName = [];
        foreach ($nodes as $node) {
            $classes = $this->declaredClasses($node);
            if ($classes === []) {
                continue;
            }

            $declared[spl_object_id($node)] = $classes;
            foreach ($classes as $class) {
                $byName[strtolower((string) $class->name)] = $node;
            }
        }

        if (count($declared) < 2) {
            return null;
        }

        $sorted  = [];
        $visited = [];
        $visit   = function (Node $node) use (&$visit, &$sorted, &$visited, $byName, $declared): void {
            $id = spl_object_id($node);
            if (isset($visited[$id])) {
                return;
            }

            $visited[$id] = true;
            foreach ($declared[$id] as $class) {
                $parents = match (true) {
                    $class instanceof Node\Stmt\Class_ => [$class->extends, ...$class->implements],
                    $class instanceof Node\Stmt\Interface_ => $class->extends,
                    $class instanceof Node\Stmt\Enum_ => $class->implements,
                    default => [],
                };
                foreach ($class->getTraitUses() as $use) {
                    array_push($parents, ...$use->traits);
                }

                foreach ($parents as $parent) {
                    $provider = $parent instanceof Node\Name ? $byName[strtolower(ltrim($parent->toString(), '\\'))] ?? null : null;
                    if ($provider !== null && $provider !== $node) {
                        $visit($provider);
                    }
                }
            }

            $sorted[] = $node;
        };

        $original = array_values(array_filter($nodes, static fn(Node $n): bool => isset($declared[spl_object_id($n)])));
        array_map($visit, $original);
        if ($sorted === $original) {
            return null;
        }

        $result = [];
        $placed = false;
        foreach ($nodes as $node) {
            if (!isset($declared[spl_object_id($node)])) {
                $result[] = $node;
            } elseif (!$placed) {
                array_push($result, ...$sorted);
                $placed = true;
            }
        }

        return $result;
    }

    /** @return list<Node\Stmt\ClassLike> */
    private function declaredClasses(Node $node): array
    {
        if ($node instanceof Node\Stmt\ClassLike) {
            return $node->name instanceof Node\Identifier ? [$node] : [];
        }

        if (!$node instanceof Node\Stmt\If_) {
            return [];
        }

        $branches = [$node->stmts, ...array_map(static fn(Node\Stmt\ElseIf_ $e): array => $e->stmts, $node->elseifs)];
        if ($node->else instanceof Node\Stmt\Else_) {
            $branches[] = $node->else->stmts;
        }

        $classes = [];
        foreach ($branches as $stmts) {
            foreach ($stmts as $stmt) {
                if (!$stmt instanceof Node\Stmt\ClassLike || !$stmt->name instanceof Node\Identifier) {
                    return [];
                }

                $classes[] = $stmt;
            }
        }

        return $classes;
    }

    /**
     * Имя для замены алиаса: '\\X' — внешний класс, полное имя без переименования.
     *
     * @param array<string, mixed> $attributes
     */
    public static function aliasTarget(string $target, array $attributes): Node\Name
    {
        return str_starts_with($target, '\\')
            ? new Node\Name\FullyQualified(substr($target, 1), $attributes)
            : new Node\Name($target, $attributes);
    }

    private function isDeclaration(Node $node): bool
    {
        return $node instanceof Node\Stmt\ClassLike
            || $node instanceof Node\Stmt\Function_
            || $node instanceof Node\Stmt\Use_
            || $node instanceof Node\Stmt\Nop
            || $node instanceof Node\Stmt\Declare_ && $node->stmts === null;
    }

    /** Строит индекс: суффикс пути → ключи fileMap. Выполняется один раз за traversal. */
    private function buildFileIndex(): void
    {
        $this->fileIndex = [];
        foreach (array_keys($this->fileMap) as $key) {
            $parts = explode('/', $key);
            $n     = count($parts);
            for ($i = 0; $i < $n; $i++) {
                $suffix                    = implode('/', array_slice($parts, $i));
                $this->fileIndex[$suffix][] = $key;
            }
        }
    }

    public function enterNode(Node $node): ?Node
    {
        // Переименовываем ссылки на короткие имена (App → Slim_App) и на имена,
        // квалифицированные импортированным namespace (DI\Doctrine → UMA_DoctrineDemo_DI_Doctrine).
        $parent = $node->getAttribute('parent');
        if (
            $node instanceof Node\Name
            && !$node instanceof Node\Name\FullyQualified
            && !$node instanceof Node\Name\Relative
            && (!$parent instanceof Node\Expr\FuncCall && !$parent instanceof Node\Expr\ConstFetch || $parent->name !== $node)
        ) {
            $lower = strtolower($node->toString());
            if (isset($this->aliasMap[$lower])) {
                return self::aliasTarget($this->aliasMap[$lower], $node->getAttributes());
            }
        }

        if (
            ($node instanceof Node\Expr\FuncCall || $node instanceof Node\Expr\ConstFetch)
            && $node->name instanceof Node\Name
            && !$node->name instanceof Node\Name\FullyQualified
            && isset($this->aliasMap[self::symbolKey($node, $node->name->toString())])
        ) {
            $node->name = self::aliasTarget($this->aliasMap[self::symbolKey($node, $node->name->toString())], $node->name->getAttributes());

            return $node;
        }

        if ($node instanceof Node\Stmt\Use_ && in_array($node->type, [Node\Stmt\Use_::TYPE_FUNCTION, Node\Stmt\Use_::TYPE_CONSTANT], true)) {
            foreach ($node->uses as $use) {
                $this->aliasMap[self::useKey($node->type, $use->getAlias()->toString())] = '\\' . $use->name->toString();
            }

            $node->setAttribute('replace', []);

            return null;
        }

        if (!$node instanceof Node\Stmt\Use_ || $node->type !== Node\Stmt\Use_::TYPE_NORMAL) {
            return null;
        }

        $replacements = [];
        foreach ($node->uses as $use) {
            $fqcn = $use->name->toString();
            $stmts = $this->resolveClass($fqcn);
            if ($stmts !== null) {
                array_push($replacements, ...$stmts);
            }

            // Регистрируем алиас для переименования ссылок в текущем файле,
            // в том числе если класс уже был инлайнен по другому use.
            if (isset($this->inlined[$fqcn])) {
                $this->aliasMap[strtolower($use->getAlias()->toString())] = strtr($fqcn, ['\\' => '_']);
                $scopeKey = $this->scopeKey($node);
                $this->qualifiedCache[$scopeKey] ??= $this->qualifiedNames($this->siblingStmts($node));
                array_push($replacements, ...$this->resolveNamespaceUse($use, $this->qualifiedCache[$scopeKey], $this->aliasMap));
            } elseif (isset($this->notInFileMap[$fqcn])) {
                $scopeKey = $this->scopeKey($node);
                $this->qualifiedCache[$scopeKey] ??= $this->qualifiedNames($this->siblingStmts($node));
                array_push($replacements, ...$this->resolveNamespaceUse($use, $this->qualifiedCache[$scopeKey], $this->aliasMap));
                // Внешний класс (`use InvalidArgumentException as BaseException;`): use удаляется,
                // поэтому короткое имя раскрываем в полное
                $this->aliasMap[strtolower($use->getAlias()->toString())] ??= '\\' . $fqcn;
            }
        }

        // Удаляем use всегда: либо заменяем на найденные классы, либо просто убираем.
        $node->setAttribute('replace', $replacements);

        return null;
    }

    /**
     * Ищет файл в fileMap по FQCN и рекурсивно разворачивает все его зависимости.
     * Помечает FQCN в $inlined до рекурсии — разрывает циклы и предотвращает дубли.
     *
     * Namespace файла должен совпасть с namespace класса: поиск идёт по суффиксу пути,
     * и без проверки `Doctrine\DBAL\Configuration` нашёлся бы как Configuration.php из ORM,
     * а `'Laminas\Diactoros\ResponseFactory'` — как ResponseFactory.php другого пакета.
     *
     * @return array<Node\Stmt>|null  null если класс не найден в fileMap или уже инлайнится
     */
    private function resolveClass(string $fqcn): ?array
    {
        if (isset($this->inlined[$fqcn]) || isset($this->notInFileMap[$fqcn])) {
            return null;
        }

        if (isset($this->declared[strtolower(strtr($fqcn, ['\\' => '_']))])) {
            $this->inlined[$fqcn] = true;
            return null;
        }

        $this->inlined[$fqcn] = true; // помечаем ДО рекурсии (in-progress)

        $parts       = explode('\\', $fqcn);
        $shortName   = end($parts);
        $suffixParts = array_slice($parts, 1);

        while ($suffixParts !== []) {
            $suffix = implode('/', $suffixParts) . '.php';
            foreach ($this->fileIndex[$suffix] ?? [] as $key) {
                // Файл, который уже обрабатывается для другого FQCN, пропускаем:
                // это предотвращает ложное совпадение (один файл — один FQCN за раз).
                if (isset($this->filesInProgress[$key])) {
                    continue;
                }

                $this->filesInProgress[$key] = true;
                $raw                         = $this->extractFromFile($key, $shortName, $fqcn);
                if ($raw !== null) {
                    $result = $this->expandInlinedUses(...$raw);
                    unset($this->filesInProgress[$key]);
                    return $result;
                }

                unset($this->filesInProgress[$key]);
            }

            array_shift($suffixParts);
        }

        // Не найден в fileMap — помечаем как внешнюю зависимость.
        unset($this->inlined[$fqcn]);
        $this->notInFileMap[$fqcn] = true;

        return null;
    }

    /**
     * Рекурсивно разворачивает use-декларации из только что инлайненного файла.
     *
     * - Нормальные use (классы) → рекурсивно инлайним если есть в fileMap,
     *   иначе оставляем как есть (внешняя зависимость).
     * - use function / use const → оставляем как есть.
     * - Объявления классов/трейтов/enum → оставляем как есть.
     *
     * Зависимости прописываются ПЕРЕД классом, который их требует.
     * Объявления файла переименовываются по алиасам только этого файла:
     * одно и то же короткое имя в разных файлах может означать разные классы.
     *
     * @param  array<Node\Stmt>       $stmts      сырой вывод extractFromFile
     * @param  array<string, string>  $aliases    алиасы объявлений самого файла (класс и компаньоны)
     * @param  string|null            $namespace  namespace файла: имена без use ищутся в нём
     * @return array<Node\Stmt>
     */
    private function expandInlinedUses(array $stmts, array $aliases, ?string $namespace): array
    {
        $deps      = [];
        $own       = [];
        $qualified = null;
        // Короткие имена из use: даже внешний `use Generator;` значит глобальный класс,
        // а не Generator из namespace файла
        $imported  = [];
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\Use_ && in_array($stmt->type, [Node\Stmt\Use_::TYPE_FUNCTION, Node\Stmt\Use_::TYPE_CONSTANT], true)) {
                foreach ($stmt->uses as $use) {
                    $aliases[self::useKey($stmt->type, $use->getAlias()->toString())] = '\\' . $use->name->toString();
                }

                continue;
            }

            if (!$stmt instanceof Node\Stmt\Use_ || $stmt->type !== Node\Stmt\Use_::TYPE_NORMAL) {
                $own[] = $stmt; // объявление класса или use function/const
                continue;
            }

            foreach ($stmt->uses as $use) {
                $imported[strtolower($use->getAlias()->toString())] = true;
                $depFqcn  = $use->name->toString();
                $resolved = $this->resolveClass($depFqcn);
                if ($resolved !== null) {
                    array_push($deps, ...$resolved);
                }

                if (isset($this->inlined[$depFqcn])) {
                    // Инлайнен сейчас, раньше или в процессе (предок в стеке рекурсии)
                    $aliases[strtolower($use->getAlias()->toString())] = strtr($depFqcn, ['\\' => '_']);
                    // `use Doctrine\ORM\Query;` — и класс Query, и namespace для `Query\ResultSetMapping`
                    $qualified ??= $this->qualifiedNames($stmts);
                    array_push($deps, ...$this->resolveNamespaceUse($use, $qualified, $aliases));
                } elseif (isset($this->notInFileMap[$depFqcn])) {
                    // Импорт namespace: инлайним классы, на которые ссылаются через него
                    $qualified ??= $this->qualifiedNames($stmts);
                    $nsClasses   = $this->resolveNamespaceUse($use, $qualified, $aliases);
                    if ($nsClasses !== []) {
                        array_push($deps, ...$nsClasses);
                        continue;
                    }

                    // Внешняя зависимость (не найдена в fileMap): use позже удаляется вместе с
                    // глобальными use, поэтому короткое имя раскрываем в полное
                    $aliases[strtolower($use->getAlias()->toString())] = '\\' . $depFqcn;
                    $deps[] = new Node\Stmt\Use_([$use], Node\Stmt\Use_::TYPE_NORMAL);
                }
            }
        }

        // Классы того же namespace доступны без use: `extends Animal` в namespace SyntaxZoo
        if ($namespace !== null) {
            foreach ($this->classNameRefs($own) as $name) {
                $lower = strtolower($name->toString());
                if (isset($aliases[$lower]) || isset($imported[$lower]) || in_array($lower, ['self', 'static', 'parent'], true)) {
                    continue;
                }

                $fqcn     = $namespace . '\\' . $name->toString();
                $resolved = $this->resolveClass($fqcn);
                if ($resolved !== null) {
                    array_push($deps, ...$resolved);
                }

                if (isset($this->inlined[$fqcn])) {
                    $aliases[$lower] = strtr($fqcn, ['\\' => '_']);
                }
            }
        }

        return [...$deps, ...$this->applyAliasMap($own, $aliases)];
    }

    /**
     * Относительные имена в позиции класса (не имена функций и констант).
     *
     * @param  array<Node> $nodes
     * @return list<Node\Name>
     */
    private function classNameRefs(array $nodes): array
    {
        $finder  = new NodeFinder();
        $notClass = new \SplObjectStorage();
        foreach ($finder->find($nodes, static fn(Node $n): bool => $n instanceof Node\Expr\FuncCall || $n instanceof Node\Expr\ConstFetch) as $n) {
            if (($n instanceof Node\Expr\FuncCall || $n instanceof Node\Expr\ConstFetch) && $n->name instanceof Node\Name) {
                $notClass[$n->name] = true;
            }
        }

        $result = [];
        foreach ($finder->findInstanceOf($nodes, Node\Name::class) as $name) {
            if (!$name instanceof Node\Name\FullyQualified && !$name instanceof Node\Name\Relative && !isset($notClass[$name])) {
                $result[] = $name;
            }
        }

        return $result;
    }

    /**
     * Обрабатывает `use Foo\Bar;`, где Bar — namespace, а не класс: находит в $scope
     * имена вида `Bar\Baz`, инлайнит классы `Foo\Bar\Baz` и регистрирует алиасы
     * для переименования `Bar\Baz` → `Foo_Bar_Baz`.
     *
     * @param  array<string, list<Node\Name>> $qualified  имена вида `Bar\Baz` в коде, где действует use,
     *                                                    сгруппированные по первой части (см. qualifiedNames)
     * @param  array<string, string>  $aliases  карта алиасов файла, дополняется
     * @return array<Node\Stmt>
     */
    private function resolveNamespaceUse(Node\UseItem $use, array $qualified, array &$aliases): array
    {
        $namespace = $use->name->toString();
        $alias     = strtolower($use->alias?->toString() ?? $use->name->getLast());

        $relatives = [];
        foreach ($qualified[$alias] ?? [] as $name) {
            $relatives[strtolower($name->toString())] = implode('\\', array_slice($name->getParts(), 1));
        }

        $output = [];
        foreach ($relatives as $lower => $rest) {
            $fqcn = $namespace . '\\' . $rest;
            if (!isset($this->inlined[$fqcn])) {
                $resolved = $this->resolveClass($fqcn);
                if ($resolved === null) {
                    continue;
                }

                array_push($output, ...$resolved);
            }

            $aliases[$lower] = strtr($fqcn, ['\\' => '_']);
        }

        return $output;
    }

    /**
     * Относительные имена из нескольких частей, сгруппированные по первой части
     * (lowercase): один обход кода на все use-декларации области.
     *
     * @param  array<Node> $scope
     * @return array<string, list<Node\Name>>
     */
    private function qualifiedNames(array $scope): array
    {
        $result = [];
        foreach (new NodeFinder()->findInstanceOf($scope, Node\Name::class) as $name) {
            if (!$name instanceof Node\Name\FullyQualified && !$name instanceof Node\Name\Relative && count($name->getParts()) >= 2) {
                $result[strtolower($name->getFirst())][] = $name;
            }
        }

        return $result;
    }

    /** Ключ области действия use: namespace-блок или весь файл. */
    private function scopeKey(Node\Stmt\Use_ $node): string
    {
        $parent = $node->getAttribute('parent');

        return $parent instanceof Node\Stmt\Namespace_ ? (string) spl_object_id($parent) : 'top';
    }

    /**
     * Операторы, в которых действует use: тело namespace-блока или весь файл.
     *
     * @return array<Node>
     */
    private function siblingStmts(Node\Stmt\Use_ $node): array
    {
        $parent = $node->getAttribute('parent');

        return $parent instanceof Node\Stmt\Namespace_ ? $parent->stmts : $this->ast;
    }

    /**
     * Применяет aliasMap к массиву нод: переименовывает однокомпонентные Name-ноды.
     * Используется для переименования коротких имён в телах инлайненных классов.
     *
     * @param  array<Node\Stmt>       $nodes
     * @param  array<string, string>  $aliasMap
     * @return array<Node\Stmt>
     */
    private function applyAliasMap(array $nodes, array $aliasMap): array
    {
        if ($aliasMap === []) {
            return $nodes;
        }

        $traverser = new NodeTraverser();
        $traverser->addVisitor(
            new class ($aliasMap) extends NodeVisitorAbstract {
                /** @param array<string, string> $aliasMap */
                public function __construct(private readonly array $aliasMap)
                {
                }

                public function enterNode(Node $node): ?Node
                {
                    // Имена функций и констант — не классы: count() не трогаем даже при `use X\Count`
                    if (($node instanceof Node\Expr\FuncCall || $node instanceof Node\Expr\ConstFetch) && $node->name instanceof Node\Name) {
                        $node->name->setAttribute('notClassName', true);

                        // `use function FastRoute\simpleDispatcher;` → simpleDispatcher() = \FastRoute\simpleDispatcher()
                        $key = UseImportVisitor::symbolKey($node, $node->name->toString());
                        if (!$node->name instanceof Node\Name\FullyQualified && isset($this->aliasMap[$key])) {
                            $node->name = UseImportVisitor::aliasTarget($this->aliasMap[$key], $node->name->getAttributes());
                            $node->name->setAttribute('notClassName', true);
                        }
                    }

                    if (
                        $node instanceof Node\Name
                        && !$node instanceof Node\Name\FullyQualified
                        && !$node instanceof Node\Name\Relative
                        && $node->getAttribute('notClassName') !== true
                    ) {
                        $lower = strtolower($node->toString());
                        if (isset($this->aliasMap[$lower])) {
                            return UseImportVisitor::aliasTarget($this->aliasMap[$lower], $node->getAttributes());
                        }
                    }

                    return null;
                }
            }
        );

        return $traverser->traverse($nodes);
    }

    /**
     * Парсит файл (с кэшированием) и возвращает [use-декларации..., переименованный ClassLike].
     * Namespace-обёртка отбрасывается. ClassLike глубоко клонируется, чтобы не мутировать кэш.
     *
     * Дополнительно извлекает «компаньонов» — все другие class/interface/trait/enum,
     * объявленные в том же файле (например, Trackable-трейт в Animal.php).
     * Компаньоны переименовываются по схеме Namespace_ShortName; их алиасы и алиас
     * самого класса возвращаются вторым элементом для переименования ссылок в файле.
     *
     * @return array{array<Node\Stmt>, array<string, string>, string|null}|null
     */
    private function extractFromFile(string $key, string $shortName, string $fqcn): ?array
    {
        if (!array_key_exists($key, $this->parseCache)) {
            try {
                $this->parseCache[$key] = $this->parser->parse($this->fileMap[$key]) ?? [];
            } catch (\Throwable) {
                $this->parseCache[$key] = null;
            }
        }

        $ast = $this->parseCache[$key];
        if ($ast === null) {
            return null;
        }

        $this->nonStrictInlined = $this->nonStrictInlined || !self::isStrictFile($ast);

        // Определяем namespace файла (если есть)
        $fileNamespace = null;
        foreach ($ast as $node) {
            if ($node instanceof Node\Stmt\Namespace_ && $node->name instanceof \PhpParser\Node\Name) {
                $fileNamespace = $node->name->toString();
                break;
            }
        }

        $expected = substr($fqcn, 0, max(0, (int) strrpos($fqcn, '\\')));
        if (strcasecmp($fileNamespace ?? '', $expected) !== 0) {
            return null;
        }

        // Ищем в плоском файле или внутри namespace-блока
        $useStmts       = [];
        $classNode      = null;
        $companionNodes = [];
        $aliases        = [strtolower($shortName) => strtr($fqcn, ['\\' => '_'])];

        $scan = function (array $stmts) use (&$useStmts, &$classNode, &$companionNodes, &$aliases, $shortName, $fileNamespace): void {
            foreach ($this->expandGroupUses($stmts) as $stmt) {
                if ($stmt instanceof Node\Stmt\Use_) {
                    $useStmts[] = $stmt;
                }
            }

            foreach ($stmts as $stmt) {
                // Условное объявление (`if (PHP_VERSION_ID >= 70400) { trait T {...} } else { trait T {...} }`):
                // встраиваем весь if, класс переименовываем во всех ветках
                if ($stmt instanceof Node\Stmt\If_ && $classNode === null) {
                    foreach (new NodeFinder()->findInstanceOf($stmt, Node\Stmt\ClassLike::class) as $inner) {
                        if ($inner->name instanceof Node\Identifier && $inner->name->toString() === $shortName) {
                            $classNode = $stmt;
                        }
                    }

                    continue;
                }

                if (
                    !($stmt instanceof Node\Stmt\Class_ ||
                    $stmt instanceof Node\Stmt\Interface_ ||
                    $stmt instanceof Node\Stmt\Trait_ ||
                    $stmt instanceof Node\Stmt\Enum_)
                ) {
                    continue;
                }

                if (!$stmt->name instanceof Node\Identifier) {
                    continue;
                }

                $name = $stmt->name->toString();

                if ($name === $shortName) {
                    $classNode = $stmt;
                    continue;
                }

                // Компаньон: другой class-like в том же файле
                $companionFqcn = $fileNamespace !== null ? sprintf('%s\%s', $fileNamespace, $name) : $name;
                if (!isset($this->inlined[$companionFqcn]) && !isset($this->notInFileMap[$companionFqcn])) {
                    $this->inlined[$companionFqcn] = true;
                    $clone       = $this->deepClone($stmt);
                    $clone->name = new Node\Identifier(strtr($companionFqcn, ['\\' => '_']));
                    $clone->setAttribute('originalName', $companionFqcn);
                    $companionNodes[] = $clone;
                }

                if (isset($this->inlined[$companionFqcn])) {
                    $aliases[strtolower($name)] = strtr($companionFqcn, ['\\' => '_']);
                }
            }
        };

        foreach ($ast as $node) {
            if ($node instanceof Node\Stmt\Namespace_) {
                $scan($node->stmts);
            } else {
                $scan([$node]);
            }
        }

        if ($classNode === null) {
            return null;
        }

        // Глубокое клонирование: не мутируем кэшированный AST при переименовании.
        $classNode = $this->deepClone($classNode);
        $declared  = $classNode instanceof Node\Stmt\ClassLike
            ? [$classNode]
            : new NodeFinder()->findInstanceOf($classNode, Node\Stmt\ClassLike::class);
        foreach ($declared as $declaration) {
            if ($declaration->name instanceof Node\Identifier && $declaration->name->toString() === $shortName) {
                $declaration->name = new Node\Identifier(strtr($fqcn, ['\\' => '_']));
                $declaration->setAttribute('originalName', $fqcn);
            }
        }

        return [[...$useStmts, ...$companionNodes, $classNode], $aliases, $fileNamespace];
    }
}
