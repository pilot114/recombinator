<?php

require_once 'WindowComposer.php';

use Cli\{WindowComposer, EdgeAlignment};

$composer = new WindowComposer();

// 1. Абсолютное позиционирование с enum
$window1 = $composer->append(
    style: '┌─┐│ │└─┘',
    content: "Окно в позиции (5, 3)",
    edge: EdgeAlignment::Absolute,
    absoluteX: 5,
    absoluteY: 3,
);
$window1->setTitle('Абсолютное окно');

// 2. Центрированное окно
$window2 = $composer->append(
    style: '╔═╗║ ║╚═╝',
    content: "Центральное окно\nНа заднем плане",
    edge: EdgeAlignment::Center,
);
$window2->title = 'Центр';  // Используем property hook
$window2->level = 0;

// 3. Окно поверх всех с использованием именованных параметров
$window3 = $composer->append(
    style: '┌─┐│ │└─┘',
    content: "Я поверх всех!",
    edge: EdgeAlignment::Absolute,
    absoluteX: 10,
    absoluteY: 8,
);
$window3->title = 'Верхнее';
$window3->level = 10;

// 4. Использование property hooks для позиции
$window1->position = ['x' => 20, 'y' => 5];

// 5. Управление уровнями
$window2->bringToFront(5);
$window3->sendToBack(3);

$composer->display();