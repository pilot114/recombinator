<?php

use PhpTui\Term\Actions;
use PhpTui\Term\ClearType;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\KeyCode;
use PhpTui\Term\Terminal;
use PhpTui\Term\TerminalInformation\Size;
use Recombinator\Support\EdgeAlignment;
use Recombinator\Support\WindowComposer;

include __DIR__ . '/../vendor/autoload.php';

/**
 * TODO
 * - исправить баги с enableMouseCapture
 * - сделать обертку для init/render/handle/release
 * - простая конфигурация управления (keymap)
 * - простая конфигурация окон
 * - сделать несколько простых демонстраций
 */

$terminal = Terminal::new();

function initTui(Terminal $terminal): void
{
    $terminal->queue(Actions::clear(ClearType::All));
    $terminal->queue(Actions::cursorHide());
//    $terminal->queue(Actions::alternateScreenEnable());
//    $terminal->queue(Actions::enableMouseCapture());
    $terminal->flush();
    $terminal->enableRawMode();
}

function unInitTui(Terminal $terminal): void
{
    $terminal->disableRawMode();
//    $terminal->queue(Actions::disableMouseCapture());
//    $terminal->queue(Actions::alternateScreenDisable());
    $terminal->queue(Actions::cursorShow());
    $terminal->queue(Actions::moveCursor(0,0));
    $terminal->queue(Actions::clear(ClearType::All));
    $terminal->flush();
}

initTui($terminal);

$size = $terminal->info(Size::class);
$composer = new WindowComposer($size->cols, $size->lines);

$x = 7;
$y = 4;

function render(Terminal $terminal, WindowComposer $composer, int $x, int $y): void
{
    $windows = $composer->grid($x, $y);
    $windows[0][0]->setTitle('test');
    $window1 = $composer->append(
        style: '┌─┐│ │└─┘',
        content: "Окно в позиции (5, 3)",
        edge: EdgeAlignment::Absolute,
        absoluteX: 5,
        absoluteY: 3,
    );
    $window1->setTitle('Абсолютное окно');
    $output = $composer->render();
    $terminal->execute(Actions::printString($output));
}

$buffer = '';

// first render
render($terminal, $composer, $x, $y);

while (true) {
    while ($event = $terminal->events()->next()) {
        try {
            render($terminal, $composer, $x, $y);
        }catch (\Throwable $exception){
            unInitTui($terminal);
        }

        if ($event instanceof CodedKeyEvent) {
            if ($event->code === KeyCode::Esc) {
                break 2;
            }
//            if ($event->code === KeyCode::Left) $x--;
//            if ($event->code === KeyCode::Right) $x++;
//            if ($event->code === KeyCode::Up) $y++;
//            if ($event->code === KeyCode::Down) $y--;
//            continue;
        }
//        if ($event instanceof CharKeyEvent) {
//            $buffer .= $event->char;
//        }
    }
    usleep(1_000);
}

unInitTui($terminal);

// var_dump($buffer);

