<?php

namespace Recombinator;

use Recombinator\Fluent;

use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;
use PhpParser\Error;

class Runner
{
    static function flatting(string $code)
    {
        $parser = (new ParserFactory)->create(ParserFactory::PREFER_PHP7);
        try {
            $ast = $parser->parse($code);
        } catch (Error $error) {
            echo "Parse error: {$error->getMessage()}\n";
            exit;
        }

        $fluent = new Fluent($ast);
        $newAST = $fluent->modify();

        return (new StandardPrinter)->prettyPrint($newAST);
    }
}