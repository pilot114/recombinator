<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;
use PhpParser\ParserFactory;

/**
 * Встраивает include/require из виртуальной карты файлов (filename → code).
 *
 * Поддерживает:
 *   require 'file.php'
 *   require __DIR__ . '/file.php'
 *   require dirname(__DIR__) . '/file.php'   (и вложенные dirname)
 *
 * Контекст файла сохраняется транзитивно: когда файл A инлайнится, его
 * include-узлы помечаются атрибутом sourceFile = путь_A. На следующем
 * проходе визитор использует это значение вместо $currentFile, поэтому
 *   vendor/autoload_runtime.php: require_once __DIR__ . '/autoload.php'
 * корректно разрешается в vendor/autoload.php, а не в public/autoload.php.
 */
#[VisitorMeta('Встраивание include/require из карты файлов')]
class FileMapIncludeVisitor extends BaseVisitor
{
    /** В этом обходе встроен файл без declare(strict_types=1) */
    private bool $nonStrictInlined = false;

    /**
     * @param array<string, string> $fileMap      relative-path → source code
     * @param string                $currentFile  relative path of the entry file (e.g. "public/index.php")
     */
    public function __construct(
        private readonly array $fileMap,
        private readonly string $currentFile = '',
    ) {
    }

    public function enterNode(Node $node): ?Node
    {
        if (!$node instanceof Node\Expr\Include_) {
            return null;
        }

        // Встроенный include исчезает из AST; оставшийся помечен как невстраиваемый —
        // правила «по всей программе» не ждут его (см. BaseVisitor::hasPendingIncludes)
        $node->setAttribute('includeUnresolvable', true);

        // Запасной автозагрузчик Composer (см. runtimeAutoload) остаётся как есть
        if ($node->getAttribute('runtimeAutoload') === true) {
            return null;
        }

        // Контекст файла: либо аннотированный из предыдущего инлайна, либо entry.
        $effectiveFile = $node->getAttribute('sourceFile') ?? $this->currentFile;

        $resolved = $this->resolve($node->expr, $effectiveFile);
        if ($resolved === null) {
            return null;
        }

        [$content, $resolvedKey] = $resolved;

        // Автозагрузчик Composer после встраивания классов не нужен — остаются
        // только файлы, которые он подключает безусловно (функции, полифиллы)
        $parent = $node->getAttribute('parent');
        if ($parent instanceof Node\Stmt\Expression) {
            $files = $this->composerAutoloadFiles($resolvedKey);
            if ($files !== null) {
                $parent->setAttribute('replace', $files);
                return null;
            }
        }

        $parser = new ParserFactory()->createForNewestSupportedVersion();
        try {
            $nodes = $parser->parse($content) ?? [];
        } catch (\Throwable) {
            return null;
        }

        // Файл с namespace нельзя вставить ни в функцию, ни в код без namespace:
        // его классы подключает UseImportVisitor, а сам require остаётся.
        // Исключение — файл только с функциями (functions.php у FastRoute): они
        // переименовываются так же, как классы (`FastRoute\simpleDispatcher` → `FastRoute_simpleDispatcher`)
        if (array_any($nodes, static fn(Node $n): bool => $n instanceof Node\Stmt\Namespace_)) {
            $nodes = $this->flattenNamespacedFunctions($nodes);
            if ($nodes === null) {
                return null;
            }
        }

        $this->nonStrictInlined = $this->nonStrictInlined || !UseImportVisitor::isStrictFile($nodes);

        // declare(strict_types) действует на свой файл и обязан быть первым оператором —
        // в середине чужого кода это синтаксическая ошибка
        $nodes = array_values(array_filter(
            $nodes,
            static fn(Node $n): bool => !$n instanceof Node\Stmt\Declare_ || $n->stmts !== null,
        ));

        // Аннотируем include-выражения внутри инлайнированного содержимого,
        // чтобы на следующем проходе они разрешались относительно своего файла.
        $this->annotateIncludes($nodes, $resolvedKey);
        $nodes = $this->relocateMagicConstants($nodes, $resolvedKey);

        $parent = $node->getAttribute('parent');
        if ($parent instanceof Node\Stmt\Expression) {
            // Завершающий return файла после инлайна завершил бы весь скрипт:
            // значение не используется, поэтому оставляем только его вычисление.
            $last = end($nodes);
            if ($last instanceof Node\Stmt\Return_) {
                $split = $this->splitTrailingReturn($nodes);
                if ($split === null) {
                    return null;
                }

                $nodes = [...$split[0], new Node\Stmt\Expression($split[1])];
            } elseif (array_any($nodes, $this->containsTopLevelReturn(...))) {
                return null;
            }

            $parent->setAttribute('replace', $nodes);
            return null;
        }

        // $x = require 'file.php'; / return new Foo(require 'file.php');
        // Файл возвращает значение завершающим return: его тело ставим перед
        // оператором, а include заменяем возвращённым выражением.
        $stmt = $this->enclosingStmtIfEvaluatedFirst($node);
        if ($stmt === null) {
            return null;
        }

        $split = $this->splitTrailingReturn($nodes);
        if ($split === null) {
            return null;
        }

        [$body, $value] = $split;
        $pending = $stmt->getAttribute('includeBody');
        $stmt->setAttribute('includeBody', [...(is_array($pending) ? $pending : []), ...$body]);

        return $value;
    }

    #[\Override]
    public function beforeTraverse(array $nodes): ?array
    {
        parent::beforeTraverse($nodes);
        $this->nonStrictInlined = false;

        return null;
    }

    /** Встроен нестрогий файл — строгий режим входного файла снимается (см. UseImportVisitor::withoutStrictTypes). */
    #[\Override]
    public function afterTraverse(array $nodes): ?array
    {
        return $this->nonStrictInlined ? UseImportVisitor::withoutStrictTypes($nodes) : null;
    }

    #[\Override]
    public function leaveNode(Node $node): int|Node|array|null
    {
        $body = $node->getAttribute('includeBody');
        if (is_array($body)) {
            $node->setAttribute('includeBody', null);
            return [...$body, $node];
        }

        return parent::leaveNode($node);
    }

    /**
     * Поднимается от include до оператора и проверяет, что include вычисляется
     * в нём первым: тогда перенос тела файла перед оператором не меняет порядок
     * побочных эффектов.
     */
    private function enclosingStmtIfEvaluatedFirst(Node\Expr\Include_ $node): Node\Stmt\Expression|Node\Stmt\Return_|null
    {
        $child = $node;
        $parent = $node->getAttribute('parent');
        while ($parent instanceof Node) {
            if ($parent instanceof Node\Stmt\Expression || $parent instanceof Node\Stmt\Return_) {
                return $parent->expr === $child ? $parent : null;
            }

            $ok = match (true) {
                $parent instanceof Node\Expr\Assign => $parent->expr === $child && $parent->var instanceof Node\Expr\Variable,
                $parent instanceof Node\Arg => $parent->value === $child,
                $parent instanceof Node\Expr\New_, $parent instanceof Node\Expr\FuncCall, $parent instanceof Node\Expr\StaticCall
                    => ($parent->args[0] ?? null) === $child
                        && ($parent instanceof Node\Expr\FuncCall ? $parent->name instanceof Node\Name : $parent->class instanceof Node\Name),
                $parent instanceof Node\Expr\MethodCall => ($parent->args[0] ?? null) === $child && $parent->var instanceof Node\Expr\Variable,
                default => false,
            };
            if (!$ok) {
                return null;
            }

            $child = $parent;
            $parent = $parent->getAttribute('parent');
        }

        return null;
    }

    /**
     * Отделяет завершающий `return expr;` от остального кода файла.
     * Возвращает null, если return не последний или встречается где-то ещё
     * вне функций/классов — тогда поток управления нельзя развернуть линейно.
     *
     * @param  array<Node\Stmt> $stmts
     * @return array{array<Node\Stmt>, Node\Expr}|null
     */
    private function splitTrailingReturn(array $stmts): ?array
    {
        $last = end($stmts);
        if (!$last instanceof Node\Stmt\Return_ || !$last->expr instanceof Node\Expr) {
            return null;
        }

        $body = array_slice($stmts, 0, -1);
        foreach ($body as $stmt) {
            if ($this->containsTopLevelReturn($stmt)) {
                return null;
            }
        }

        return [$body, $last->expr];
    }

    /** Есть ли return, относящийся к самому файлу (не к вложенной функции/классу). */
    private function containsTopLevelReturn(Node $node): bool
    {
        if ($node instanceof Node\Stmt\Return_) {
            return true;
        }

        if ($node instanceof Node\FunctionLike || $node instanceof Node\Stmt\ClassLike) {
            return false;
        }

        foreach ($node->getSubNodeNames() as $sub) {
            $val = $node->$sub;
            foreach (is_array($val) ? $val : [$val] as $child) {
                if ($child instanceof Node && $this->containsTopLevelReturn($child)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Файл с namespace, в котором только функции, → код без namespace: имена
     * разрешены полностью, свои функции переименованы в `Ns_func`.
     * null — в файле есть классы или код вне namespace.
     *
     * @param  array<Node> $nodes
     * @return array<Node>|null
     */
    private function flattenNamespacedFunctions(array $nodes): ?array
    {
        foreach ($nodes as $node) {
            if (!$node instanceof Node\Stmt\Namespace_ && !$node instanceof Node\Stmt\Declare_ && !$node instanceof Node\Stmt\Nop) {
                return null;
            }
        }

        $finder = new \PhpParser\NodeFinder();
        if ($finder->findInstanceOf($nodes, Node\Stmt\ClassLike::class) !== []) {
            return null;
        }

        $nodes = new \PhpParser\NodeTraverser(new \PhpParser\NodeVisitor\NameResolver())->traverse($nodes);

        /** @var array<string, string> $own lowercase полное имя → плоское */
        $own = [];
        foreach ($finder->findInstanceOf($nodes, Node\Stmt\Function_::class) as $function) {
            $fqn = $function->namespacedName?->toString() ?? $function->name->toString();
            $own[strtolower($fqn)] = strtr($fqn, ['\\' => '_']);
            $function->name        = new Node\Identifier($own[strtolower($fqn)]);
        }

        foreach ($finder->findInstanceOf($nodes, Node\Expr\FuncCall::class) as $call) {
            if (!$call->name instanceof Node\Name) {
                continue;
            }

            $namespaced = $call->name->getAttribute('namespacedName');
            $fqn        = strtolower($namespaced instanceof Node\Name ? $namespaced->toString() : $call->name->toString());
            if (isset($own[$fqn])) {
                $call->name = new Node\Name\FullyQualified($own[$fqn]);
            }
        }

        $result = [];
        foreach ($nodes as $node) {
            if ($node instanceof Node\Stmt\Namespace_) {
                foreach ($node->stmts as $stmt) {
                    if (!$stmt instanceof Node\Stmt\Use_ && !$stmt instanceof Node\Stmt\GroupUse) {
                        $result[] = $stmt;
                    }
                }
            }
        }

        return $result;
    }

    /**
     * `vendor/autoload.php` Composer → `require_once` файлов из autoload_files.php
     * (пути относительно входного файла: туда попадает встроенный код).
     * null — это не автозагрузчик Composer.
     *
     * @return list<Node\Stmt\Expression>|null
     */
    private function composerAutoloadFiles(string $key): ?array
    {
        if (basename($key) !== 'autoload.php') {
            return null;
        }

        $vendorDir = dirname($key) === '.' ? '' : dirname($key);
        $prefix    = $vendorDir === '' ? '' : $vendorDir . '/';
        if (!isset($this->fileMap[$prefix . 'composer/autoload_real.php'])) {
            return null;
        }

        $baseDir  = dirname($vendorDir === '' ? '.' : $vendorDir);
        $entryDir = dirname($this->currentFile);
        $up       = $entryDir === '.' || $entryDir === '' ? '' : str_repeat('../', substr_count($entryDir, '/') + 1);

        $files = $this->fileMap[$prefix . 'composer/autoload_files.php'] ?? '';
        preg_match_all("/\\$(vendorDir|baseDir)\\s*\\.\\s*'([^']*)'/", $files, $m, PREG_SET_ORDER);
        $result = [];
        foreach ($m as [, $var, $path]) {
            $target  = $this->normalizePath(($var === 'vendorDir' ? $vendorDir : ($baseDir === '.' ? '' : $baseDir)) . '/' . $path);
            $include = new Node\Expr\Include_(
                new Node\Expr\BinaryOp\Concat(new Node\Scalar\MagicConst\Dir(), new Node\Scalar\String_('/' . $up . $target)),
                Node\Expr\Include_::TYPE_REQUIRE_ONCE,
            );
            $include->setAttribute('sourceFile', $this->currentFile);
            $result[] = new Node\Stmt\Expression($include);
        }

        return [...$result, ...$this->runtimeAutoload($up . ($vendorDir === '' ? '' : $vendorDir . '/') . 'autoload.php')];
    }

    /**
     * Имена классов, собранные во время выполнения (`sprintf('Faker\Provider\%s\%s', ...)`),
     * статически не разрешить. Поэтому вместо автозагрузчика:
     * - первым — алиас исходного имени на встроенный класс (`Faker\Provider\Base` →
     *   `Faker_Provider_Base`): одна копия класса, а не две;
     * - затем Composer — для классов, которые не встроены.
     *
     * @return list<Node\Stmt>
     */
    private function runtimeAutoload(string $autoloadPath): array
    {
        $code = <<<'PHP'
            <?php
            spl_autoload_register(static function (string $class): void {
                $flat = strtr($class, '\\', '_');
                if (class_exists($flat, false) || interface_exists($flat, false) || trait_exists($flat, false) || enum_exists($flat, false)) {
                    class_alias($flat, $class);
                }
            }, true, true);
            PHP;
        $stmts = new ParserFactory()->createForNewestSupportedVersion()->parse($code) ?? [];
        foreach ($stmts as $stmt) {
            $stmt->setAttribute('runtimeAutoload', true);
        }

        $composer = new Node\Expr\Include_(
            new Node\Expr\BinaryOp\Concat(new Node\Scalar\MagicConst\Dir(), new Node\Scalar\String_('/' . $autoloadPath)),
            Node\Expr\Include_::TYPE_REQUIRE_ONCE,
        );
        $composer->setAttribute('runtimeAutoload', true);

        return [...$stmts, new Node\Stmt\Expression($composer)];
    }

    /**
     * Возвращает [content, resolvedKey] или null если файл не найден.
     *
     * @return array{string, string}|null
     */
    private function resolve(Node $expr, string $effectiveFile): ?array
    {
        if ($expr instanceof Node\Scalar\String_) {
            return $this->lookupWithKey(ltrim($expr->value, '/\\'));
        }

        if (
            $expr instanceof Node\Expr\BinaryOp\Concat &&
            $expr->right instanceof Node\Scalar\String_
        ) {
            $depth = $this->dirDepth($expr->left);
            if ($depth !== null) {
                return $this->lookupRelativeWithKey($depth, $expr->right->value, $effectiveFile);
            }
        }

        return null;
    }

    /**
     * Returns the number of dirname() wrappers around __DIR__, or null.
     *
     *   __DIR__                    → 0
     *   dirname(__DIR__)           → 1
     *   dirname(dirname(__DIR__))  → 2
     */
    private function dirDepth(Node $expr): ?int
    {
        if ($expr instanceof Node\Scalar\MagicConst\Dir) {
            return 0;
        }

        if (
            $expr instanceof Node\Expr\FuncCall
            && $expr->name instanceof Node\Name
            && strtolower((string) $expr->name) === 'dirname'
            && count($expr->args) === 1
            && $expr->args[0] instanceof Node\Arg
        ) {
            $inner = $this->dirDepth($expr->args[0]->value);
            return $inner !== null ? $inner + 1 : null;
        }

        return null;
    }

    /**
     * @return array{string, string}|null
     */
    private function lookupRelativeWithKey(int $depth, string $suffix, string $effectiveFile): ?array
    {
        $suffix = ltrim($suffix, '/\\');

        if ($effectiveFile !== '') {
            $parts = array_filter(explode('/', dirname($effectiveFile)), fn($p): bool => $p !== '.');
            for ($i = 0; $i < $depth; $i++) {
                array_pop($parts);
            }

            $base     = implode('/', $parts);
            $resolved = $this->normalizePath(($base !== '' ? $base . '/' : '') . $suffix);
            if (isset($this->fileMap[$resolved])) {
                return [$this->fileMap[$resolved], $resolved];
            }
        }

        return $this->lookupWithKey($suffix);
    }

    /**
     * @return array{string, string}|null
     */
    private function lookupWithKey(string $path): ?array
    {
        if (isset($this->fileMap[$path])) {
            return [$this->fileMap[$path], $path];
        }

        $base = basename($path);
        if (isset($this->fileMap[$base])) {
            return [$this->fileMap[$base], $base];
        }

        return null;
    }

    /** Resolves . and .. segments in a relative path. */
    private function normalizePath(string $path): string
    {
        $out = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '..') {
                array_pop($out);
            } elseif ($part !== '' && $part !== '.') {
                $out[] = $part;
            }
        }

        return implode('/', $out);
    }

    /**
     * Встроенный код выполняется из входного файла: __DIR__ и __FILE__ подключённого
     * файла из другого каталога пересчитываются (`bootstrap.php` в корне, вход —
     * `public/index.php`: `__DIR__` → `__DIR__ . '/..'`). Пути внутри include
     * не трогаем — их разрешает этот визитор по атрибуту sourceFile.
     *
     * @param  array<Node> $nodes
     * @return array<Node>
     */
    private function relocateMagicConstants(array $nodes, string $fileKey): array
    {
        $entryParts = array_values(array_filter(explode('/', dirname($this->currentFile)), static fn(string $p): bool => $p !== '.' && $p !== ''));
        $fileParts  = array_values(array_filter(explode('/', dirname($fileKey)), static fn(string $p): bool => $p !== '.' && $p !== ''));
        if ($entryParts === $fileParts) {
            return $nodes;
        }

        $common = 0;
        while (isset($entryParts[$common], $fileParts[$common]) && $entryParts[$common] === $fileParts[$common]) {
            $common++;
        }

        $ups   = count($entryParts) - $common;
        $downs = array_slice($fileParts, $common);
        // `__DIR__ . '/..'`, а не dirname(__DIR__): выражение должно годиться и для `const X = __DIR__;`
        $suffix = '/' . implode('/', [...array_fill(0, $ups, '..'), ...$downs]);
        $dir    = static fn(): Node\Expr => new Node\Expr\BinaryOp\Concat(new Node\Scalar\MagicConst\Dir(), new Node\Scalar\String_($suffix));

        $visitor = new class ($dir, basename($fileKey)) extends \PhpParser\NodeVisitorAbstract {
            /** @param \Closure(): Node\Expr $dir */
            public function __construct(private readonly \Closure $dir, private readonly string $file)
            {
            }

            public function enterNode(Node $node): ?int
            {
                return $node instanceof Node\Expr\Include_ ? \PhpParser\NodeVisitor::DONT_TRAVERSE_CHILDREN : null;
            }

            public function leaveNode(Node $node): ?Node
            {
                return match (true) {
                    $node instanceof Node\Scalar\MagicConst\Dir => ($this->dir)(),
                    $node instanceof Node\Scalar\MagicConst\File
                        => new Node\Expr\BinaryOp\Concat(($this->dir)(), new Node\Scalar\String_('/' . $this->file)),
                    default => null,
                };
            }
        };

        return new \PhpParser\NodeTraverser($visitor)->traverse($nodes);
    }

    /**
     * Рекурсивно ставит атрибут sourceFile на все Include_-узлы внутри $nodes,
     * чтобы следующий проход знал, из какого файла они пришли.
     *
     * @param array<Node> $nodes
     */
    private function annotateIncludes(array $nodes, string $sourceFile): void
    {
        foreach ($nodes as $node) {
            if (!$node instanceof Node) {
                continue;
            }

            if ($node instanceof Node\Expr\Include_) {
                $node->setAttribute('sourceFile', $sourceFile);
            }

            foreach ($node->getSubNodeNames() as $sub) {
                $val = $node->$sub;
                if ($val instanceof Node) {
                    $this->annotateIncludes([$val], $sourceFile);
                } elseif (is_array($val)) {
                    $this->annotateIncludes($val, $sourceFile);
                }
            }
        }
    }
}
