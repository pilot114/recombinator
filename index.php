<?php

require './vendor/autoload.php';

use PhpParser\Error;
use PhpParser\NodeDumper;
use PhpParser\ParserFactory;

use PhpParser\Node;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Expr\Include_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

use PhpParser\PrettyPrinter;

use Recombinator\Visitor\FunctionVisitor;


$code = file_get_contents('./tests/code/index.php');

$parser = (new ParserFactory)->create(ParserFactory::PREFER_PHP7);
try {
    $ast = $parser->parse($code);
} catch (Error $error) {
    echo "Parse error: {$error->getMessage()}\n";
    exit;
}

//PhpParser\Node\Stmt
//PhpParser\Node\Expr
//PhpParser\Node\Scalar

$traverser = new NodeTraverser();
$traverser->addVisitor(new class extends NodeVisitorAbstract {

    // буфер функций
    public $buffer = [];

    public function enterNode(Node $node)
    {
        if ($node instanceof Include_) {
            // включить в файл, предварительно рекурсивно обработав
        }
        if ($node instanceof Function_) {
            $fVisitor = new FunctionVisitor();
            $this->buffer[$node->name] = $fVisitor->enterNode($node);
        }
//        echo (new NodeDumper)->dump($node) . "\n";die();
    }

    public function leaveNode(Node $node)
    {
        // удаляем объявление функции
        if ($node instanceof Function_) {
            return NodeTraverser::REMOVE_NODE;
        }

        // если пользовательская функция - заменяем на тело из буфера
        if ($node instanceof FuncCall) {
//            return NodeTraverser::REMOVE_NODE;
            if (array_key_exists($node->name->getLast(), $this->buffer)) {
                return $this->buffer[$node->name->getLast()];
            }
        }
    }
});

$ast = $traverser->traverse($ast);

$prettyPrinter = new PrettyPrinter\Standard;
$code = $prettyPrinter->prettyPrint($ast);

echo $code;