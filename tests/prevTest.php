<?php

include './vendor/autoload.php';

use PhpParser\{Node,
    NodeTraverser,
    NodeVisitor\NodeConnectingVisitor,
    NodeVisitorAbstract,
    ParserFactory
};

$traverser = new NodeTraverser;
$traverser->addVisitor(new NodeConnectingVisitor());
$parser = (new ParserFactory)->create(ParserFactory::PREFER_PHP7);
$code = '<?php
echo 1;
echo 2;
';
$stmts = $parser->parse($code);
$stmts = $traverser->traverse($stmts);

$traverser->addVisitor(new class extends NodeVisitorAbstract {
    public function leaveNode(Node $node)
    {
        if ($node->hasAttribute('previous')) {
            echo sprintf("Find prev: %s\n", get_class($node->getAttribute('previous')));
        }
        if ($node->hasAttribute('next')) {
            echo sprintf("Find next: %s\n", get_class($node->getAttribute('next')));
        }
    }
});

$traverser->traverse($stmts);
