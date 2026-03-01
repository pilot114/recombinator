<?php

declare(strict_types=1);

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;
use PhpParser\ParserFactory;

/**
 * подключаемые файлы - подключаем)
 */
#[VisitorMeta('Устаревший обработчик include/require — заменён Inliner (шаг 0)')]
class IncludeVisitor extends BaseVisitor
{
    public function __construct(protected string $entryPoint)
    {
    }

    /**
     * @throws \Recombinator\Support\IncludeException
     */
    public function enterNode(Node $node): int|Node|array|null
    {
        if (
            $node instanceof Node\Expr\Include_
            && $node->expr instanceof Node\Scalar\String_
        ) {
            $baseDir = dirname($this->entryPoint);
            $path = $baseDir . '/' . $node->expr->value;
            $realPath = realpath($path);
            $realBaseDir = realpath($baseDir);

            if ($realPath === false || !file_exists($path)) {
                throw new \Recombinator\Support\IncludeException(
                    sprintf("Include file does not exist: %s", $path)
                );
            }

            if ($realBaseDir !== false && !str_starts_with($realPath, $realBaseDir)) {
                throw new \Recombinator\Support\IncludeException(
                    sprintf("Path traversal detected: %s", $node->expr->value)
                );
            }

            $scopeContent = file_get_contents($realPath);
            if ($scopeContent === false) {
                throw new \Recombinator\Support\IncludeException(
                    sprintf("Failed to read include file: %s", $realPath)
                );
            }

            $parser = new ParserFactory()->createForNewestSupportedVersion();
            $nodes = $parser->parse($scopeContent);

            $node->getAttribute('parent')->setAttribute('replace', $nodes);
        }

        return null;
    }
}
