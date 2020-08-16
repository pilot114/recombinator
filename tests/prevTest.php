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
$traverser->addVisitor(new class extends NodeVisitorAbstract {

    public function leaveNode(Node $node)
    {
        if ($node->hasAttribute('previous')) {
            echo sprintf("Find prev: %s\n", get_class($node->getAttribute('previous')));

            echo sprintf("Find next-prev: %s\n", get_class($node->getAttribute('previous')->getAttribute('next')));
        }
        if ($node->hasAttribute('next')) {
            echo sprintf("Find next: %s\n", get_class($node->getAttribute('next')));
        }
    }
});

$code = '<?php
echo 1;
echo 2;
';

$parser = (new ParserFactory)->create(ParserFactory::PREFER_PHP7);
$stmts = $parser->parse($code);
$traverser->traverse($stmts);
