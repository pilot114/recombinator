<?php

namespace Recombinator\Transformation\Visitor;

use PhpParser\Node;
use PhpParser\Node\Stmt\Function_;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;

/**
 * Подготавливает кеш скопов
 */
class ScopeVisitor extends BaseVisitor
{
    public function __construct(
        protected $entryPoint = null,
        protected $cacheDir = null
    ) {
    }

    public function enterNode(Node $node): void
    {
        if ($node instanceof Function_) {
            $name = $node->name->name;
            if ($this->cacheDir !== null) {
                $filename = sprintf("Function_%s_%s.php", $name, uniqid());
                $code = "<?php\n\n" . (new StandardPrinter)->prettyPrint([$node]);
                file_put_contents($this->cacheDir . '/' . $filename, $code);
                // Only mark for removal if we're caching
                $node->setAttribute('remove', true);
            }
        }

        if ($node instanceof Node\Stmt\Class_) {
            $name = $node->name->name;
            if ($this->cacheDir !== null) {
                $filename = sprintf("Class_%s_%s.php", $name, uniqid());
                $code = "<?php\n\n" . (new StandardPrinter)->prettyPrint([$node]);
                file_put_contents($this->cacheDir . '/' . $filename, $code);
                // Only mark for removal if we're caching
                $node->setAttribute('remove', true);
            }
        }
    }
}
