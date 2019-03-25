<?php

use function Onion\Framework\EventLoop\after;
use function Onion\Framework\EventLoop\loop;
use Onion\Framework\WebSocket\Client;
use Onion\Framework\WebSocket\Frame;
use Onion\Framework\WebSocket\Stream;
require __DIR__ . '/../vendor/autoload.php';

after(1000, function () {
    $client = new Client('tcp://localhost:1337', 10);
    $client->connect([
        'Sec-WebSocket-Protocol' => 'soap',
    ])->then(function (Stream $socket) {
        if (!$socket->isClosed()) {
            echo "> {$socket->read()}";
            $socket->close();
        } else {
            $socket->write(new Frame((string) time()), true);
        }
    });
});


loop()->start();
