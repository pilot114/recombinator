<?php

namespace Recombinator\Visitor;

use PhpParser\Node;
use PhpParser\ParserFactory;

/**
 * подключаемые файлы - подключаем)
 */
class IncludeVisitor extends BaseVisitor
{
    protected $entryPoint;

    public function __construct($entryPoint)
    {
        $this->entryPoint = $entryPoint;
    }

    public function enterNode(Node $node)
    {
        if (
            $node instanceof Node\Expr\Include_
        ) {
            $path = dirname($this->entryPoint) . '/' . $node->expr->value;
            if (!file_exists($path)) {
                throw new \Exception(sprintf("Include file dont exist: %s", $path));
            }
            $scopeContent = file_get_contents($path);
            $parser = (new ParserFactory)->create(ParserFactory::PREFER_PHP7);
            $nodes = $parser->parse($scopeContent);

            $node->getAttribute('parent')->setAttribute('replace', $nodes);
        }
    }
}