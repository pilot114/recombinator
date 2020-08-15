<?php

namespace Recombinator\Visitor;

use PhpParser\Node;
use PhpParser\Node\Stmt\Function_;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;

/**
 * Подготавливает кеш скопов
 */
class ScopeVisitor extends BaseVisitor
{
    protected $entryPoint;
    protected $cacheDir;

    public function __construct($entryPoint, $cacheDir)
    {
        $this->entryPoint = $entryPoint;
        $this->cacheDir = $cacheDir;
    }

    public function enterNode(Node $node)
    {
        if ($node instanceof Function_) {
            $name = $node->name->name;
            $filename = sprintf("Function_%s_%s.php", $name, uniqid());
            $code = "<?php\n\n" . (new StandardPrinter)->prettyPrint([$node]);
            file_put_contents($this->cacheDir . '/' . $filename, $code);
            $node->setAttribute('remove', true);
        }
        if ($node instanceof Node\Stmt\Class_) {
            $name = $node->name->name;
            $filename = sprintf("Class_%s_%s.php", $name, uniqid());
            $code = "<?php\n\n" . (new StandardPrinter)->prettyPrint([$node]);
            file_put_contents($this->cacheDir . '/' . $filename, $code);
            $node->setAttribute('remove', true);
        }
    }
}