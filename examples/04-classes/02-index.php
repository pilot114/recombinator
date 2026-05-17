<?php

require_once __DIR__ . '/Point.php';

$origin = new Point(0.0, 0.0);
$target = new Point(3.0, 4.0);

$distance = $origin->distanceTo($target);
$label    = $target->label();

echo "Distance to " . $label . " = " . $distance;

$unused = $origin->x * 0;
