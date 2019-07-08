<?php
use Onion\Framework\Event\Dispatcher;
use Onion\Framework\Event\ListenerProviders\AggregateProvider;
use Onion\Framework\Event\ListenerProviders\SimpleProvider;
use Onion\Framework\Loop\Interfaces\ResourceInterface;
use Onion\Framework\Loop\Scheduler;
use Onion\Framework\Server\Server;
use Onion\Framework\WebSocket\Drivers\WebSocketDriver;
use Onion\Framework\WebSocket\Events\CloseEvent;
use Onion\Framework\WebSocket\Events\ConnectEvent;
use Onion\Framework\WebSocket\Events\HandshakeEvent;
use Onion\Framework\WebSocket\Events\MessageEvent;
use Onion\Framework\WebSocket\Frame;
use Onion\Framework\WebSocket\Listeners\HandshakeListener;

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
            $frame = $event->getConnection()->read();

            yield $event->getConnection()->wait(ResourceInterface::OPERATION_WRITE);

            $event->getConnection()->write(
                new Frame("Server: {$frame->getData()}", Frame::OPCODE_TEXT, true)
            );
        }
    ],
    CloseEvent::class => [
        function (CloseEvent $event) {
            echo "Connection #{$event->getConnection()->getDescriptorId()} closed ({$event->getCode()})\n";
        }
    ],
    HandshakeEvent::class => [
        function (HandshakeEvent $event) use (&$dispatcher) {
            return call_user_func(new HandshakeListener($dispatcher, ['chat']), $event);
        }
    ],
]));
$dispatcher = new Dispatcher($provider);
$driver = new WebSocketDriver($dispatcher);

$server = new Server($dispatcher);
$server->attach($driver, '0.0.0.0', 9501);

$scheduler = new Scheduler;
$scheduler->add($server->start());

$scheduler->start();
