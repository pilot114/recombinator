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
 *
 * Поиск: сначала точное совпадение ключа, затем по basename.
 * Применяется только когда include стоит как самостоятельный statement.
 */
#[VisitorMeta('Встраивание include/require из карты файлов')]
class FileMapIncludeVisitor extends BaseVisitor
{
    /** @param array<string, string> $fileMap filename → source code */
    public function __construct(private readonly array $fileMap) {}

    public function enterNode(Node $node): ?Node
    {
        if (!$node instanceof Node\Expr\Include_) {
            return null;
        }

        $content = $this->resolveContent($node->expr);
        if ($content === null) {
            return null;
        }

        $parser = new ParserFactory()->createForNewestSupportedVersion();
        try {
            $nodes = $parser->parse($content) ?? [];
        } catch (\Throwable) {
            return null;
        }

        $parent = $node->getAttribute('parent');
        if ($parent instanceof Node\Stmt\Expression) {
            $parent->setAttribute('replace', $nodes);
        }

        return null;
    }

    private function resolveContent(Node $expr): ?string
    {
        if ($expr instanceof Node\Scalar\String_) {
            return $this->lookup($expr->value);
        }

        // __DIR__ . '/file.php'
        if (
            $expr instanceof Node\Expr\BinaryOp\Concat &&
            $expr->left instanceof Node\Scalar\MagicConst\Dir &&
            $expr->right instanceof Node\Scalar\String_
        ) {
            return $this->lookup(ltrim($expr->right->value, '/\\'));
        }

        return null;
    }

    private function lookup(string $path): ?string
    {
        return $this->fileMap[$path] ?? $this->fileMap[basename($path)] ?? null;
    }
}
