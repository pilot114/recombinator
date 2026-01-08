<?php

use Recombinator\Support\ColorDiffer;

include __DIR__ . '/../vendor/autoload.php';

$differ = new ColorDiffer();

echo $differ->diff(
    "Hello World!\ntest\ntest3",
    "Hello World!\ntest2\ntest3",
);

