<?php

/**
 * Pure computation blocks that can be extracted into functions.
 *
 * Before: inline pure computations
 * After: extracted into reusable functions
 */

// Distance calculation block - candidate for extraction
$x1 = 0;
$y1 = 0;
$x2 = 10;
$y2 = 10;
$dx = $x2 - $x1;
$dy = $y2 - $y1;
$distance = sqrt($dx * $dx + $dy * $dy);

// Quadratic formula block - candidate for extraction
$a = 1;
$b = -5;
$c = 6;
$discriminant = $b * $b - 4 * $a * $c;
$sqrtDiscriminant = sqrt($discriminant);
$x1_root = (-$b + $sqrtDiscriminant) / (2 * $a);
$x2_root = (-$b - $sqrtDiscriminant) / (2 * $a);

// Statistics block - candidate for extraction
$values = [10, 20, 30, 40, 50];
$count = 5;
$sum = 10 + 20 + 30 + 40 + 50;
$average = $sum / $count;
$min = 10;
$max = 50;
$range = $max - $min;

echo "Distance: " . $distance;
echo "Roots: " . $x1_root . ", " . $x2_root;
echo "Average: " . $average;
