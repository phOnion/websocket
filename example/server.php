<?php

use Onion\Framework\Event\Dispatcher;
use Onion\Framework\Event\ListenerProviders\{AggregateProvider, SimpleProvider};
use Onion\Framework\Http\Events\RequestEvent;
use Onion\Framework\Loop\Scheduler;
use Onion\Framework\Server\Drivers\NetworkDriver;
use Onion\Framework\Server\Events\MessageEvent as EventsMessageEvent;
use Onion\Framework\Server\Server;
use Onion\Framework\WebSocket\Drivers\WebSocketDriver;
use Onion\Framework\WebSocket\Events\{CloseEvent, ConnectEvent, MessageEvent};
use Onion\Framework\WebSocket\Frame;
use Onion\Framework\WebSocket\Listeners\HandshakeListener;
use Onion\Framework\WebSocket\Types\Types;

use function Onion\Framework\Loop\scheduler;
use Onion\Framework\Http\Listeners\HttpMessageListener;

require_once __DIR__ . '/../vendor/autoload.php';

$provider = new AggregateProvider;
$provider->addProvider(new SimpleProvider([
    ConnectEvent::class => [
        function (ConnectEvent $event) {
            echo "Client Connected\n";
        }
    ],
    MessageEvent::class => [
        function (MessageEvent $event) {
            $frame = $event->message;

            if ($frame !== null) {
                $event->setResponse(
                    new Frame("Server: {$frame->getData()}", Types::TEXT, true)
                );
            }
        }
    ],
    EventsMessageEvent::class => [
        function ($ev) use (&$dispatcher) {
            (new HttpMessageListener($dispatcher))($ev);
        },
    ],
    CloseEvent::class => [
        function (CloseEvent $event) {
            echo "Connection closed ({$event->getCode()})\n";
        }
    ],
    RequestEvent::class => [
        function (RequestEvent $event) use (&$dispatcher) {
            return call_user_func(new HandshakeListener($dispatcher), $event);
        }
    ],
]));
$dispatcher = new Dispatcher($provider);
$driver = new NetworkDriver($dispatcher);

$server = new Server($dispatcher);
$server->attach($driver, 'tcp://0.0.0.0', 9501);

$server->start();
scheduler()->start();
