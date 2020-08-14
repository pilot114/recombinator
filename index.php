<?php

require './vendor/autoload.php';

use Recombinator\Parser;

$path = './tests/code/index2.php';
$rec = new Parser($path);

$rec->parseScope();
$rec->collapseScope();

//echo $rec->dumpAST();
echo $rec->prettyPrint(true);
