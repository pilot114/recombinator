<?php

require './vendor/autoload.php';

use Recombinator\Parser;

$path = './tests/code/index.php';
$cachePath = './tests/cache';
if (!is_dir($cachePath)) {
    mkdir($cachePath);
}
$rec = new Parser($path, $cachePath);
$rec->parseScopes();

