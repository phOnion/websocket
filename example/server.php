<?php

use LibDNS\Records\Types\Type;
use Onion\Framework\Event\Dispatcher;
use Onion\Framework\Event\ListenerProviders\{AggregateProvider, SimpleProvider};
use Onion\Framework\Http\Events\RequestEvent;
use Onion\Framework\Server\Drivers\NetworkDriver;
use Onion\Framework\Server\Events\MessageEvent as EventsMessageEvent;
use Onion\Framework\Server\Server;
use Onion\Framework\WebSocket\Events\{CloseEvent, ConnectEvent, MessageEvent};
use Onion\Framework\WebSocket\Frame;
use Onion\Framework\WebSocket\Listeners\HandshakeListener;
use Onion\Framework\WebSocket\Types\Types;

use function Onion\Framework\Loop\scheduler;

use Onion\Framework\Http\Listeners\HttpMessageListener;
use Onion\Framework\Server\Events\StartEvent;

require_once __DIR__ . '/../vendor/autoload.php';

$provider = new AggregateProvider;
$provider->addProvider(new SimpleProvider([
    StartEvent::class => [
        fn () => printf('Started!'),
    ],
    ConnectEvent::class => [
        function () {
            echo "Client Connected\n";
        }
    ],
    MessageEvent::class => [
        function (MessageEvent $event) {
            $frame = $event->message;

            switch ($frame->getOpcode()) {
                case Types::PING:
                    $event->setResponse(
                        new Frame(
                            type: Types::PONG,
                            masked: false
                        )
                    );
                    break;
                default:
                    var_dump($frame);
                    $event->setResponse(
                        new Frame(
                            data: "Server: {$frame->getData()}",
                            type: Types::TEXT,
                            masked: false
                        )
                    );
                    break;
            }
        }
    ],
    EventsMessageEvent::class => [
        function (EventsMessageEvent $ev) use (&$dispatcher) {
            (new HttpMessageListener($dispatcher))($ev);
        },
    ],
    CloseEvent::class => [
        function (CloseEvent $event) {
            $event->getConnection()->close();
            echo "Connection closed ({$event->getCode()})\n";
        }
    ],
    RequestEvent::class => [
        function (RequestEvent $event) use (&$dispatcher) {
            (new HandshakeListener($dispatcher))($event);
        }
    ],
]));
$dispatcher = new Dispatcher($provider);
$driver = new NetworkDriver($dispatcher);

$server = new Server($dispatcher);
$server->attach($driver, 'tcp://0.0.0.0', 9501);

$server->start();
scheduler()->start();
