<?php

require './vendor/autoload.php';

use Recombinator\Parser;

$path = './tests/code/index.php';
$rec = new Parser($path);

$rec->parseScope();
$rec->collapseScope();

echo $rec->prettyPrint();
