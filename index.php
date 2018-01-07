<?php

require './vendor/autoload.php';

use PhpParser\Error;
use PhpParser\NodeDumper;
use PhpParser\ParserFactory;

use PhpParser\Node;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Expr\Include_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
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
    protected $buffer = [];

    public function enterNode(Node $node)
    {
        if ($node instanceof Include_) {
            // включить в файл, предварительно рекурсивно обработав
        }
        if ($node instanceof Function_) {

            $fVisitor = new FunctionVisitor();
            $fVisitor->enterNode($node);
//            $this->buffer[$node->name] = $traverserInFunction->traverse([$node]);

            echo (new NodeDumper)->dump($node) . "\n";
        }
        if ($node instanceof FuncCall) {
        }
//        echo (new NodeDumper)->dump($node) . "\n";
    }

    public function leaveNode(Node $node)
    {
        // удаляем объявление функции
        if ($node instanceof Function_) {
            return NodeTraverser::REMOVE_NODE;
        }

        // если пользовательская функция - заменяем на тело из буфера
        if ($node instanceof FuncCall) {
            $funcName = $node->name->getLast();
            if (array_key_exists($funcName, $this->buffer)) {
                return $this->buffer[$funcName];
            }
        }
    }
});

$ast = $traverser->traverse($ast);

$prettyPrinter = new PrettyPrinter\Standard;
$code = $prettyPrinter->prettyPrint($ast);

echo $code;