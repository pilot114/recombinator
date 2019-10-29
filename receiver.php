<?php

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
