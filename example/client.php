<?php

use Onion\Framework\WebSocket\Client;
use Onion\Framework\WebSocket\Frame;
use Onion\Framework\WebSocket\Stream;
require __DIR__ . '/../vendor/autoload.php';

$client = new Client('http://localhost:1337', 5);
$socket = $client->connect()->then(function (Stream $socket) {
    $socket->write(new Frame((string) time()), true);
    echo "> {$socket->read()}";
    $socket->close();
})->await();
