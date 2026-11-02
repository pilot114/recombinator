<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;
use PhpParser\ParserFactory;

/**
 * Ядро Symfony берёт прогретый контейнер (допущение `symfonyContainerCached`: на сервере
 * выполнен cache:warmup, кэш свежий). KernelTrait::initializeContainer подключает файл
 * кэша по вычисленному пути, а если его нет или он устарел — собирает контейнер заново.
 * При допущении верна первая ветка, и подключение заменяется значением файла-загрузчика:
 *
 *   if (is_file($cachePath) && \is_object($this->container = include $cachePath) && …) {…}
 *   … сборка …
 *   →
 *   $this->container = new \Container3uUE0Op\App_KernelProdContainer([...], __DIR__ . '/../var/cache/prod' . …);
 *   $this->container->set('kernel', $this);
 *
 * Класс контейнера встраивается из карты файлов (var/cache), и имена в нём переименовываются
 * вместе с программой: иначе кэш со старыми именами классов (`App\Entity` в настройках
 * Doctrine) не совпадал бы с плоскими именами программы. Загрузчик — единственный
 * var/cache/<env>/<Класс>Container.php в карте; метод узнаётся по телу: подключение файла,
 * buildContainer() и dumpContainer().
 */
#[VisitorMeta('Ядро Symfony: прогретый контейнер вместо подключения кэша и сборки', targets: [Node\Stmt\ClassMethod::class])]
class SymfonyContainerCacheVisitor extends BaseVisitor
{
    private ?Node\Expr $container = null;

    /** @param array<string, string> $fileMap */
    public function __construct(private readonly array $fileMap, private readonly string $entry)
    {
    }

    #[\Override]
    public function beforeTraverse(array $nodes): ?array
    {
        parent::beforeTraverse($nodes);
        $this->container ??= $this->loaderValue();

        return null;
    }

    public function enterNode(Node $node): ?Node
    {
        if (!$node instanceof Node\Stmt\ClassMethod || !$this->container instanceof Node\Expr || !$this->buildsContainer($node)) {
            return null;
        }

        $property  = static fn(): Node\Expr\PropertyFetch => new Node\Expr\PropertyFetch(new Node\Expr\Variable('this'), 'container');
        $container = $this->deepClone($this->container);
        assert($container instanceof Node\Expr);
        $node->stmts = [
            new Node\Stmt\Expression(new Node\Expr\Assign($property(), $container)),
            new Node\Stmt\Expression(new Node\Expr\MethodCall($property(), 'set', [
                new Node\Arg(new Node\Scalar\String_('kernel')),
                new Node\Arg(new Node\Expr\Variable('this')),
            ])),
        ];

        return $node;
    }

    private function buildsContainer(Node\Stmt\ClassMethod $method): bool
    {
        $calls = array_map(
            static fn(Node\Expr\MethodCall $call): string => $call->name instanceof Node\Identifier ? $call->name->toLowerString() : '',
            $this->findNode(Node\Expr\MethodCall::class, $method->stmts ?? []),
        );

        return in_array('buildcontainer', $calls, true) && in_array('dumpcontainer', $calls, true)
            && $this->findNode(Node\Expr\Include_::class, $method->stmts ?? []) !== [];
    }

    /** Значение, которое возвращает единственный загрузчик контейнера в var/cache; null — его нет или их несколько. */
    private function loaderValue(): ?Node\Expr
    {
        $loaders = array_filter(
            array_keys($this->fileMap),
            static fn(string $path): bool => preg_match('#^var/cache/[^/]+/[A-Za-z0-9_]+Container\.php$#', $path) === 1,
        );
        if (count($loaders) !== 1) {
            return null;
        }

        $path  = reset($loaders);
        $nodes = new ParserFactory()->createForNewestSupportedVersion()->parse($this->fileMap[$path]) ?? [];
        $last  = end($nodes);
        if (!$last instanceof Node\Stmt\Return_ || !$last->expr instanceof Node\Expr\New_) {
            return null;
        }

        // __DIR__ загрузчика — из каталога входного файла, где выполняется программа
        $relocated = FileMapIncludeVisitor::relocateMagicConstants([$last], $path, $this->entry)[0];
        assert($relocated instanceof Node\Stmt\Return_ && $relocated->expr instanceof Node\Expr);

        return $relocated->expr;
    }
}
