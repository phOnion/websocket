<?php

namespace Onion\Framework\WebSocket\Listeners;

use GuzzleHttp\Psr7\{Message, Response};
use Onion\Framework\Http\Events\RequestEvent;
use Onion\Framework\Loop\Interfaces\ResourceInterface;
use Onion\Framework\WebSocket\Events\{CloseEvent, ConnectEvent, HandshakeEvent, MessageEvent};
use Onion\Framework\WebSocket\{WebSocket, Frame};
use Onion\Framework\WebSocket\Types\Types;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ServerRequestInterface;
use Onion\Framework\Loop\Types\Operation;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

use function Onion\Framework\Loop\{coroutine, tick};

class HandshakeListener
{
    private const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
    private const PATTERN = '/^[+\/0-9A-Za-z]{21}[AQgw]==$/';

    public function __construct(private readonly EventDispatcherInterface $dispatcher)
    {
    }

    public function __invoke(RequestEvent $event)
    {
        $request = $event->request;
        $connection = $event->connection;
        if ($request->getHeaderLine('upgrade') === 'websocket') {
            $challenge = $request->getHeaderLine('sec-websocket-key');
            if (
                preg_match(static::PATTERN, $challenge) !== 1 ||
                strlen(base64_decode($challenge)) !== 16
            ) {
                $connection->close();
                return;
            }

            $ev = $this->dispatcher->dispatch(
                new HandshakeEvent($request, new Response(
                    101,
                    [
                        'upgrade' => $request->hasHeader('upgrade') ?
                            $request->getHeaderLine('upgrade') : 'websocket',
                        'connection' => $request->hasHeader('connection') ?
                            $request->getHeaderLine('connection') : 'Upgrade',
                        'sec-websocket-accept' => $request->hasHeader('sec-websocket-accept') ?
                            $request->getHeaderLine('sec-websocket-accept') :
                            base64_encode(sha1($challenge . static::GUID, true)),
                        'sec-websocket-version' => $request->hasHeader('sec-websocket-version') ?
                            $request->getHeaderLine('sec-websocket-version') : '13',
                    ],
                )),
            );

            /** @var HandshakeEvent $event */
            $connection->wait(Operation::WRITE);
            if ($ev->getResponse() === null) {
                throw new RuntimeException(
                    'Handshake was not'
                );
            }

            $event->setResponse($ev->getResponse());

            coroutine(function (ResourceInterface $connection, RequestInterface $request) {
                $ws = new WebSocket($connection);
                $this->dispatcher->dispatch(new ConnectEvent(
                    $request,
                    $ws,
                ));

                while (!$connection->eof()) {
                    try {
                        $frame = $ws->read();

                        $event = new MessageEvent(
                            $request,
                            $frame,
                        );
                        switch ($frame->getOpcode()) {
                            case Types::PING:
                                $event->setResponse(
                                    new Frame(
                                        data: $frame->getData(),
                                        type: Types::PONG,
                                        masked: true,
                                    ),
                                );
                                break;
                            case Types::CLOSE:
                                $this->dispatcher->dispatch(
                                    new CloseEvent($request, $ws, substr($frame->getData(), 0, 4))
                                );

                                $ws->close();
                                break 2;
                        }

                        /** @var MessageEvent $event */
                        $event = $this->dispatcher->dispatch($event);
                        $response = $event->getResponse();
                        if ($response instanceof Frame) {
                            $ws->write($event->getResponse());
                        }
                    } catch (\Throwable $ex) {
                        $this->dispatcher->dispatch(
                            new CloseEvent($request, $ws, $ex->getCode())
                        );
                        break;
                    }
                    tick();
                }
            }, [$connection, $request]);
        }
    }
}
