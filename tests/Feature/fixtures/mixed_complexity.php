<?php

/**
 * Mixed complexity example combining multiple patterns.
 *
 * Demonstrates:
 * - Constant inlining
 * - isset to coalesce
 * - Math pre-computation
 * - String concatenation
 * - If-return to ternary
 */

class Calculator
{
    const PI = 3.14159;

    const E = 2.71828;

    const PRECISION = 2;

    public function circleArea($radius): string
    {
        $area = self::PI * $radius * $radius;
        if ($area > 100) {
            return "large";
        }

        return "small";
    }
}

$defaultX = 0;
if (isset($_GET["x"])) {
    $defaultX = $_GET["x"];
}

$defaultY = 0;
if (isset($_GET["y"])) {
    $defaultY = $_GET["y"];
}

$offsetX = 10;
$offsetY = 20;
$finalX = $defaultX + $offsetX;
$finalY = $defaultY + $offsetY;

$baseMultiplier = 2;
$extraMultiplier = 3;
$totalMultiplier = $baseMultiplier * $extraMultiplier;

$prefix = "Point";
$separator = ": ";
$label = $prefix . $separator;

echo $label . "(" . $finalX . ", " . $finalY . ")";
echo "Multiplier: " . $totalMultiplier;
