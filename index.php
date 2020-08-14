<?php

require './vendor/autoload.php';

use Recombinator\Parser;

$path = './tests/code/index.php';
$cachePath = './tests/cache';
$rec = new Parser($path, $cachePath);

$rec->parseScopes();

//echo $rec->dumpAST('index.php');
echo $rec->prettyPrint('index.php', true);
$rec->updateCache();
