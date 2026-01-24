<?php

require_once 'WindowComposer.php';

use Cli\{WindowComposer, EdgeAlignment};

$composer = new WindowComposer();

$menu = $composer->append(
    style: '╔═╗║ ║╚═╝',
    content: "1. Новый файл\n2. Открыть\n3. Сохранить\n4. Выход",
    edge: EdgeAlignment::Left,
    size: 25,
);
$menu->title = 'Меню';
$menu->level = 10;

$main = $composer->append(
    style: '┌─┐│ │└─┘',
    content: "Основное содержимое\n\nЗдесь ваш контент...",
    edge: EdgeAlignment::Center,
);
$main->title = 'Документ';

$status = $composer->append(
    style: '+-+| |+-+',
    content: "Готово | Строка: 1 | Столбец: 1",
    edge: EdgeAlignment::Bottom,
    size: 3,
);

$composer->display();