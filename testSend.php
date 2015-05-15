<?php

require_once 'noSQLite.php';

$socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
socket_bind($socket, '127.0.0.1');
socket_connect($socket, '127.0.0.1', 4242);

$request = str_pad('THIS IS A TEST, BOO', 2048, 'X');

$header = pack('L', strlen($request));

noSQLite::printHexDump($header);

socket_write($socket, $header, 4);
socket_write($socket, $request, strlen($request));

if (false !== ($bytes = socket_recv($socket, $buf, 2048, MSG_WAITALL))) {
    echo "Read $bytes bytes from socket_recv(). Closing socket..." . PHP_EOL;
    print $buf . PHP_EOL;
} else {
    echo "socket_recv() failed; reason: " . socket_strerror(socket_last_error($socket)) . "\n";
}
socket_close($socket);