<?php

require './vendor/autoload.php';

use Recombinator\Parser;

$code = file_get_contents('./tests/code/index.php');

$rec = new Parser($code);

/**
 * Запускает парсинг и начинает строить дерево скопов c заданным уровнем вложености
 */
$rec->parseScopeWithLevel(0);
/**
 * Подменяет код, используя дерево скопов
 */
$rec->collapseScope();

echo $rec->dump();
//echo $rec->print();
