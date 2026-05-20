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

        // Контекст файла: либо аннотированный из предыдущего инлайна, либо entry.
        $effectiveFile = $node->getAttribute('sourceFile') ?? $this->currentFile;

        $resolved = $this->resolve($node->expr, $effectiveFile);
        if ($resolved === null) {
            return null;
        }

        [$content, $resolvedKey] = $resolved;

        $parser = new ParserFactory()->createForNewestSupportedVersion();
        try {
            $nodes = $parser->parse($content) ?? [];
        } catch (\Throwable) {
            return null;
        }

        // Аннотируем include-выражения внутри инлайнированного содержимого,
        // чтобы на следующем проходе они разрешались относительно своего файла.
        $this->annotateIncludes($nodes, $resolvedKey);

        $parent = $node->getAttribute('parent');
        if ($parent instanceof Node\Stmt\Expression) {
            $parent->setAttribute('replace', $nodes);
        }

        return null;
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
