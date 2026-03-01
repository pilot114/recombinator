<?php

function double($x): int|float {
    return $x * 2;
}

$a = 5;
$b = double($a);
$c = $b + 10;
echo $c;
