<?php

/**
 * Запрос к сокету - выполнить кусок кода
 */
function runCode($code)
{
    $socket = socket_create(AF_UNIX, SOCK_DGRAM, 0);
    socket_sendto($socket, "Hello World!", 12, 0, "/tmp/myserver.sock", 0);
    echo "sent\n";
}

/**
 * Запрос к сокету - получить тело функции / класса
 */
function getDefinition($callName)
{
    $socket = socket_create(AF_UNIX, SOCK_DGRAM, 0);
    socket_sendto($socket, "Hello World!", 12, 0, "/tmp/myserver.sock", 0);
    echo "sent\n";
}

/**
* Выполнение кода в отдельном процессе.
* !!! Выполнять можно только то, что не имеет побочных эффектов !!!
*/

$file = "/tmp/recombinator.sock";

if (is_readable($file)) {
    unlink($file);
}

$socket = socket_create(AF_UNIX, SOCK_DGRAM, 0);

if (socket_bind($socket, $file) === false) {
    echo "bind failed";
    exit;
}

if (socket_recvfrom($socket, $buf, 64 * 1024, 0, $source) === false) {
    echo "recv_from failed";
    exit;
}

echo "received: " . $buf . "\n";
