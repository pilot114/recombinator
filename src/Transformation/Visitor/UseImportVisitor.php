<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;
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
     * Карта алиасов из успешно инлайненных use-деклараций:
     * lowercase короткое имя → плоское FQCN-имя (например 'app' → 'Slim_App').
     * Используется для переименования всех ссылок на короткое имя в текущем файле.
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
        $this->buildFileIndex();
        return null;
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
        // Переименовываем ссылки на короткие имена (App → Slim_App).
        // Только однокомпонентные имена: многокомпонентные (App\Sub) не трогаем.
        if (
            $node instanceof Node\Name
            && !$node instanceof Node\Name\FullyQualified
            && !$node instanceof Node\Name\Relative
            && count($node->getParts()) === 1
        ) {
            $lower = strtolower($node->toString());
            if (isset($this->aliasMap[$lower])) {
                return new Node\Name($this->aliasMap[$lower], $node->getAttributes());
            }
        }

        if (!$node instanceof Node\Stmt\Use_ || $node->type !== Node\Stmt\Use_::TYPE_NORMAL) {
            return null;
        }

        $replacements = [];
        foreach ($node->uses as $use) {
            $fqcn = $use->name->toString();
            if (isset($this->inlined[$fqcn])) {
                continue;
            }

            $stmts = $this->resolveClass($fqcn);
            if ($stmts !== null) {
                array_push($replacements, ...$stmts);

                // Регистрируем алиас для переименования ссылок в текущем файле.
                $parts = explode('\\', $fqcn);
                $alias = $use->alias?->toString() ?? end($parts);
                $this->aliasMap[strtolower($alias)] = strtr($fqcn, ['\\' => '_']);
            }
        }

        // Применяем накопленный aliasMap к replacement-нодам, чтобы короткие имена
        // внутри тел классов были переименованы прямо сейчас, а не в следующих проходах.
        if ($replacements !== [] && $this->aliasMap !== []) {
            $replacements = $this->applyAliasMap($replacements);
        }

        // Удаляем use всегда: либо заменяем на найденные классы, либо просто убираем.
        $node->setAttribute('replace', $replacements);

        return null;
    }

    /**
     * Ищет файл в fileMap по FQCN и рекурсивно разворачивает все его зависимости.
     * Помечает FQCN в $inlined до рекурсии — разрывает циклы и предотвращает дубли.
     *
     * @return array<Node\Stmt>|null  null если класс не найден в fileMap или уже инлайнится
     */
    private function resolveClass(string $fqcn): ?array
    {
        if (isset($this->inlined[$fqcn]) || isset($this->notInFileMap[$fqcn])) {
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
                    $result = $this->expandInlinedUses($raw);
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
     *
     * @param  array<Node\Stmt> $stmts  сырой вывод extractFromFile
     * @return array<Node\Stmt>
     */
    private function expandInlinedUses(array $stmts): array
    {
        $output = [];
        foreach ($stmts as $stmt) {
            if (!$stmt instanceof Node\Stmt\Use_ || $stmt->type !== Node\Stmt\Use_::TYPE_NORMAL) {
                $output[] = $stmt; // объявление класса или use function/const
                continue;
            }

            foreach ($stmt->uses as $use) {
                $depFqcn  = $use->name->toString();
                $resolved = $this->resolveClass($depFqcn);
                if ($resolved !== null) {
                    // Класс найден — инлайним и регистрируем алиас
                    $depParts = explode('\\', $depFqcn);
                    $depAlias = $use->alias?->toString() ?? end($depParts);
                    $this->aliasMap[strtolower($depAlias)] = strtr($depFqcn, ['\\' => '_']);
                    array_push($output, ...$resolved);
                } elseif (isset($this->notInFileMap[$depFqcn])) {
                    // Внешняя зависимость (не найдена в fileMap) — оставляем use как есть
                    $output[] = new Node\Stmt\Use_([$use], Node\Stmt\Use_::TYPE_NORMAL);
                }

                // Иначе: in-progress (предок в стеке рекурсии) — use не нужен,
                // класс будет инлайнен на уровне выше.
            }
        }

        return $output;
    }

    /**
     * Применяет aliasMap к массиву нод: переименовывает однокомпонентные Name-ноды.
     * Используется для переименования коротких имён в телах инлайненных классов.
     *
     * @param  array<Node\Stmt> $nodes
     * @return array<Node\Stmt>
     */
    private function applyAliasMap(array $nodes): array
    {
        $aliasMap  = $this->aliasMap;
        $traverser = new NodeTraverser();
        $traverser->addVisitor(
            new class ($aliasMap) extends NodeVisitorAbstract {
                /** @param array<string, string> $aliasMap */
                public function __construct(private readonly array $aliasMap)
                {
                }

                public function enterNode(Node $node): ?Node
                {
                    if (
                        $node instanceof Node\Name
                        && !$node instanceof Node\Name\FullyQualified
                        && !$node instanceof Node\Name\Relative
                        && count($node->getParts()) === 1
                    ) {
                        $lower = strtolower($node->toString());
                        if (isset($this->aliasMap[$lower])) {
                            return new Node\Name($this->aliasMap[$lower], $node->getAttributes());
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
     * Компаньоны переименовываются по схеме Namespace_ShortName и регистрируются
     * в aliasMap, чтобы ссылки внутри тел классов корректно переписались.
     *
     * @return array<Node\Stmt>|null
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

        // Определяем namespace файла (если есть)
        $fileNamespace = null;
        foreach ($ast as $node) {
            if ($node instanceof Node\Stmt\Namespace_ && $node->name instanceof \PhpParser\Node\Name) {
                $fileNamespace = $node->name->toString();
                break;
            }
        }

        // Ищем в плоском файле или внутри namespace-блока
        $useStmts       = [];
        $classNode      = null;
        $companionNodes = [];

        $scan = function (array $stmts) use (&$useStmts, &$classNode, &$companionNodes, $shortName, $fileNamespace): void {
            foreach ($stmts as $stmt) {
                if ($stmt instanceof Node\Stmt\Use_) {
                    $useStmts[] = $stmt;
                }
            }

            foreach ($stmts as $stmt) {
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
                    $companionNodes[] = $clone;
                    $this->aliasMap[strtolower($name)] = strtr($companionFqcn, ['\\' => '_']);
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
        $classNode       = $this->deepClone($classNode);
        $classNode->name = new Node\Identifier(strtr($fqcn, ['\\' => '_']));

        return [...$useStmts, ...$companionNodes, $classNode];
    }
}
