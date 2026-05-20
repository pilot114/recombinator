<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;
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
    /** @var array<string, true> уже вставленные FQCN */
    private array $inlined = [];

    /**
     * Карта алиасов из успешно инлайненных use-деклараций:
     * lowercase короткое имя → плоское FQCN-имя (например 'app' → 'Slim_App').
     * Используется для переименования всех ссылок на короткое имя в текущем файле.
     *
     * @var array<string, string>
     */
    private array $aliasMap = [];

    /** @param array<string, string> $fileMap relative-path → source code */
    public function __construct(private readonly array $fileMap)
    {
    }

    #[\Override]
    public function beforeTraverse(array $nodes): ?array
    {
        parent::beforeTraverse($nodes);
        $this->inlined   = [];
        $this->aliasMap  = [];
        return null;
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

        // Удаляем use всегда: либо заменяем на найденные классы, либо просто убираем.
        $node->setAttribute('replace', $replacements);

        return null;
    }

    /**
     * Ищет файл в fileMap по FQCN и рекурсивно разворачивает все его зависимости.
     * Помечает FQCN в $inlined до рекурсии — разрывает циклы и предотвращает дубли.
     *
     * @return array<Node\Stmt>|null  null если класс не найден в fileMap
     */
    private function resolveClass(string $fqcn): ?array
    {
        if (isset($this->inlined[$fqcn])) {
            return null; // уже инлайнен или цикл
        }

        $this->inlined[$fqcn] = true; // помечаем ДО рекурсии

        $parts       = explode('\\', $fqcn);
        $shortName   = end($parts);
        $suffixParts = array_slice($parts, 1);

        while ($suffixParts !== []) {
            $suffix = implode('/', $suffixParts) . '.php';
            foreach ($this->fileMap as $key => $code) {
                if ($key === $suffix || str_ends_with($key, '/' . $suffix)) {
                    $raw = $this->extractFromFile($code, $shortName, $fqcn);
                    if ($raw !== null) {
                        return $this->expandInlinedUses($raw);
                    }
                }
            }

            array_shift($suffixParts);
        }

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
                } else {
                    // Внешняя зависимость — оставляем use как есть
                    $output[] = new Node\Stmt\Use_([$use], Node\Stmt\Use_::TYPE_NORMAL);
                }
            }
        }

        return $output;
    }

    /**
     * Парсит файл и возвращает [use-декларации из файла..., переименованный ClassLike].
     * Namespace-обёртка отбрасывается.
     *
     * @return array<Node\Stmt>|null
     */
    private function extractFromFile(string $code, string $shortName, string $fqcn): ?array
    {
        $parser = new ParserFactory()->createForNewestSupportedVersion();
        try {
            $ast = $parser->parse($code) ?? [];
        } catch (\Throwable) {
            return null;
        }

        // Ищем в плоском файле или внутри namespace-блока
        $useStmts  = [];
        $classNode = null;

        $scan = static function (array $stmts) use (&$useStmts, &$classNode, $shortName): void {
            foreach ($stmts as $stmt) {
                if ($stmt instanceof Node\Stmt\Use_) {
                    $useStmts[] = $stmt;
                }
            }

            foreach ($stmts as $stmt) {
                if (
                    ($stmt instanceof Node\Stmt\Class_ ||
                     $stmt instanceof Node\Stmt\Interface_ ||
                     $stmt instanceof Node\Stmt\Trait_ ||
                     $stmt instanceof Node\Stmt\Enum_) &&
                    $stmt->name instanceof Node\Identifier &&
                    $stmt->name->toString() === $shortName
                ) {
                    $classNode = $stmt;
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

        // Переименовываем: App\Controller\BlogController → App_Controller_BlogController
        $classNode->name = new Node\Identifier(strtr($fqcn, ['\\' => '_']));

        return [...$useStmts, $classNode];
    }
}
