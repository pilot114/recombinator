<?php

use Recombinator\Core\Parser;

require './vendor/autoload.php';

$path = './tests/code/index.php';
$cachePath = './tests/cache';
if (!is_dir($cachePath)) {
    mkdir($cachePath);
}
$rec = new Parser($path, $cachePath);
$rec->run();

