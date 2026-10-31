<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;
use Recombinator\Support\ExternalCode;

/**
 * Удаляет запасной `require_once __DIR__ . '/../vendor/autoload.php'` (см.
 * FileMapIncludeVisitor::runtimeAutoload), когда программе нечего загружать
 * автозагрузкой: все классы, которые она может назвать (в том числе собранными
 * строками — StringValues), уже встроены, а файлы autoload_files.php встроены или
 * удалены как ничего не делающие.
 *
 * Алиас исходных имён на встроенные классы (spl_autoload_register) остаётся: он
 * связывает `'Faker\Provider\en_US\Address'` с `Faker_Provider_en_US_Address`, а также
 * имена в подключаемых во время выполнения файлах (сгенерированные прокси Doctrine).
 */
#[VisitorMeta('Удаление автозагрузчика Composer, когда загружать нечего', targets: [Node\Expr\Include_::class])]
class RuntimeAutoloadVisitor extends BaseVisitor
{
    private bool $unneeded = false;

    /** @param array<string, string> $fileMap */
    public function __construct(private readonly array $fileMap)
    {
    }

    #[\Override]
    public function beforeTraverse(array $nodes): ?array
    {
        parent::beforeTraverse($nodes);
        $this->unneeded = false;

        // Оставшиеся подключения — данные во время выполнения (сгенерированный прокси
        // Doctrine, кэш маршрутов FastRoute): исходные имена в них связывает алиас
        $includes = $this->findNode(Node\Expr\Include_::class);
        if (
            $this->fileMap === [] || $this->hasPendingIncludes()
            || !array_any($includes, static fn(Node\Expr\Include_ $i): bool => $i->getAttribute('runtimeAutoload') === true)
        ) {
            return null;
        }

        $classes = [];
        foreach ($this->findNode(Node\Stmt\ClassLike::class) as $class) {
            if ($class->name instanceof Node\Identifier) {
                $classes[$class->name->toLowerString()] = $class;
            }
        }

        $this->unneeded = ExternalCode::forProgram($this->fileMap, $classes, $nodes)->loadableClassFiles() === [];

        return null;
    }

    public function enterNode(Node $node): ?Node
    {
        if (
            $this->unneeded && $node instanceof Node\Stmt\Expression && $node->expr instanceof Node\Expr\Include_
            && $node->expr->getAttribute('runtimeAutoload') === true
        ) {
            $node->setAttribute('remove', true);
        }

        return null;
    }
}
