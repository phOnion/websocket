<?php

use function Onion\Framework\Loop\coroutine;
use function Onion\Framework\Loop\scheduler;

use GuzzleHttp\Psr7\Request;
use Onion\Framework\Event\Dispatcher;
use Onion\Framework\Event\ListenerProviders\AggregateProvider;
use Onion\Framework\Event\ListenerProviders\SimpleProvider;
use Onion\Framework\Loop\Timer;
use Onion\Framework\Loop\Types\Operation;
use Onion\Framework\WebSocket\Client;
use Onion\Framework\WebSocket\Events\DataEvent;
use Onion\Framework\WebSocket\Frame;
use Onion\Framework\WebSocket\Types\CloseReasons;
use Onion\Framework\WebSocket\Types\Types;
use Onion\Framework\WebSocket\WebSocket;

error_reporting(E_ALL);
ini_set('display_errors', 1);

require __DIR__ . '/../vendor/autoload.php';

$provider = new AggregateProvider();
$provider->addProvider(new SimpleProvider([
    DataEvent::class => [
        function (DataEvent $event) {
            $frame = $event->connection->read();
            var_dump($frame);

            if ($frame->getOpcode() === Types::PING) {
                $event->connection->write(new Frame(
                    Types::PONG,
                    true,
                    data: $frame->getData()
                ));
            }
        }
    ],
]));

$client = new Client(new Dispatcher($provider));
coroutine(function () use ($client) {
    $request = new Request('GET', 'http://localhost:9501', [
        'Upgrade' => ['websocket'],
        'Connection' => ['upgrade'],
        'Sec-WebSocket-Version' => [13],
        'Sec-WebSocket-Key' => [base64_encode(random_bytes(16))],
        'Sec-WebSocket-Protocol' => ['chat', 'superchat'],
    ]);

    $ws = $client->connect($request);

    $frame = new Frame(
        data: 'example 1234',
        type: Types::TEXT,
        masked: true,
    );

    $ws->getResource()->wait(Operation::WRITE);

    echo 'writing!';
    $ws->write($frame);
    $ws->write($frame);
    Timer::after(function (WebSocket $ws) {
        $ws->close(CloseReasons::INTERNAL_ERROR, masked: true);
    }, 120000, [$ws]);
});

scheduler()->start();
