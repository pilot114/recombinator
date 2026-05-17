<?php

function calculateTotal(array $items, float $taxRate = 0.2): float
{
    $subtotal = 0;
    foreach ($items as $item) {
        $subtotal = $subtotal + $item['price'];
    }
    $tax   = $subtotal * $taxRate;
    $total = $subtotal + $tax;
    return $total;
}

$products = [
    ['name' => 'Apple',  'price' => 1 + 1],
    ['name' => 'Banana', 'price' => 3 * 2],
];

$result = calculateTotal($products);

if (true) {
    echo "Total: " . $result;
} else {
    echo "never";
}

$isArr = is_array($products);
$len   = 1 + 2 + 3;
