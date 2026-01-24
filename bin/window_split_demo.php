#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/WindowComposer.php';

use Cli\WindowComposer;
use Cli\BorderMode;

// Пример 1: PerWindow - каждое окно имеет свою границу (по умолчанию)
echo "=== BorderMode::PerWindow (у каждого окна своя граница) ===\n\n";

$composer = new WindowComposer(60, 10);
$windows = $composer->splitVertical(2, '┌─┐│ │└─┘', BorderMode::PerWindow);

$windows[0]->setTitle('Left');
$windows[0]->setContent("Content 1\nLine 2");

$windows[1]->setTitle('Right');
$windows[1]->setContent("Content 2\nLine 2");

echo $composer->render();
echo "\n\n";

// Пример 2: Between - граница между блоками
echo "=== BorderMode::Between (граница между блоками) ===\n\n";

$composer = new WindowComposer(60, 10);
$windows = $composer->splitVertical(2, '┌─┐│ │└─┘', BorderMode::Between);

$windows[0]->setContent("Left panel\nNo borders\nJust content");
$windows[1]->setContent("Right panel\nSeparator in the middle");

echo $composer->render();
echo "\n\n";

// Пример 3: None - без границ
echo "=== BorderMode::None (без границ) ===\n\n";

$composer = new WindowComposer(60, 8);
$windows = $composer->splitVertical(2, '┌─┐│ │└─┘', BorderMode::None);

$windows[0]->setContent("Left panel\nNo borders at all");
$windows[1]->setContent("Right panel\nPure content");

echo $composer->render();
echo "\n\n";

// Пример 4: Горизонтальное разделение с Between
echo "=== Horizontal split with BorderMode::Between ===\n\n";

$composer = new WindowComposer(60, 12);
$windows = $composer->splitHorizontal(3, '┌─┐│ │└─┘', BorderMode::Between);

$windows[0]->setContent("Header area");
$windows[1]->setContent("Main content\nMultiple lines here");
$windows[2]->setContent("Footer / Status bar");

echo $composer->render();
echo "\n\n";

// Пример 5: Сетка 2x2 с Between
echo "=== Grid 2x2 with BorderMode::Between ===\n\n";

$composer = new WindowComposer(60, 12);
$grid = $composer->grid(2, 2, '┌─┐│ │└─┘', BorderMode::Between);

$grid[0][0]->setContent("Cell [0,0]\nTop-Left");
$grid[0][1]->setContent("Cell [0,1]\nTop-Right");
$grid[1][0]->setContent("Cell [1,0]\nBottom-Left");
$grid[1][1]->setContent("Cell [1,1]\nBottom-Right");

echo $composer->render();
echo "\n\n";

// Пример 6: Сетка 3x2 без границ
echo "=== Grid 3x2 with BorderMode::None ===\n\n";

$composer = new WindowComposer(60, 8);
$grid = $composer->grid(3, 2, '┌─┐│ │└─┘', BorderMode::None);

for ($row = 0; $row < 2; $row++) {
    for ($col = 0; $col < 3; $col++) {
        $grid[$row][$col]->setContent("[$row,$col]");
    }
}

echo $composer->render();
echo "\n\n";

// Пример 7: Сравнение всех режимов на одинаковых данных
echo "=== Comparison of all modes (2 columns) ===\n\n";

$modes = [
    'PerWindow' => BorderMode::PerWindow,
    'Between' => BorderMode::Between,
    'None' => BorderMode::None,
];

foreach ($modes as $name => $mode) {
    echo "--- $name ---\n";
    $composer = new WindowComposer(40, 6);
    $windows = $composer->splitVertical(2, '┌─┐│ │└─┘', $mode);
    $windows[0]->setContent("Left content");
    $windows[1]->setContent("Right content");
    echo $composer->render();
    echo "\n\n";
}
