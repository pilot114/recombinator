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
                $this->inlined[$fqcn] = true;
                array_push($replacements, ...$stmts);

                // Регистрируем алиас для дальнейшего переименования ссылок.
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
     * Ищет файл в fileMap по FQCN, извлекает use-декларации и объявление класса
     * без namespace-обёртки. Класс переименовывается по FQCN для уникальности.
     *
     * Стратегия поиска (от длинного суффикса к короткому):
     *   App\Controller\BlogController → Controller/BlogController.php → BlogController.php
     *
     * @return array<Node\Stmt>|null  [use*, classLike]
     */
    private function resolveClass(string $fqcn): ?array
    {
        $parts       = explode('\\', $fqcn);
        $shortName   = end($parts);
        $suffixParts = array_slice($parts, 1);

        while (!empty($suffixParts)) {
            $suffix = implode('/', $suffixParts) . '.php';
            foreach ($this->fileMap as $key => $code) {
                if ($key === $suffix || str_ends_with($key, '/' . $suffix)) {
                    $result = $this->extractFromFile($code, $shortName, $fqcn);
                    if ($result !== null) {
                        return $result;
                    }
                }
            }
            array_shift($suffixParts);
        }

        return null;
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
                     $stmt instanceof Node\Stmt\Enum_)
                    && $stmt->name instanceof Node\Identifier
                    && $stmt->name->toString() === $shortName
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
