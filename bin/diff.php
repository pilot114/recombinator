<?php

include '../vendor/autoload.php';

$differ = new \Recombinator\ColorDiffer();

echo $differ->diff(
    "Hello World!\ntest\ntest3",
    "Hello World!\ntest2\ntest3",
);

