<?php

function double(int $x): int
{
    return $x * 2;
}

function square(int $x): int
{
    return $x * $x;
}

$a = double(5);
$b = square(3);
$c = double(4) + square(2);

echo $a + $b + $c;
