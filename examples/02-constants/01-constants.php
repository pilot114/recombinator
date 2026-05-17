<?php

$x   = 2 + 3 * 4;
$s   = 'foo' . 'bar' . '!';
$b   = true && false;
$n   = !false;
$cmp = 10 > 5 ? 'big' : 'small';

if (false) {
    echo "мёртвый код";
} else {
    echo "живой: " . $x;
}

$unused = $x * 0 + 0;
