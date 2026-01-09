<?php

/**
 * Complex mathematical expressions that can be pre-computed.
 *
 * Before: verbose calculations with intermediate variables
 * After: pre-computed constant values
 */

$pi = 3.14159;
$radius = 10;

$diameter = $radius * 2;
$circumference = 2 * $pi * $radius;
$area = $pi * $radius * $radius;

$width = 5;
$height = 10;
$rectangleArea = $width * $height;
$rectanglePerimeter = 2 * ($width + $height);

$a = 3;
$b = 4;
$c = 5;
$sum = $a + $b + $c;
$product = $a * $b * $c;
$average = ($a + $b + $c) / 3;

echo "Circle area: " . $area;
echo "Rectangle area: " . $rectangleArea;
echo "Sum: " . $sum;
