<?php

declare(strict_types=1);

namespace Onion\Framework\WebSocket;

use GuzzleHttp\Psr7\Message;
use Onion\Framework\Http\Client as HttpClient;
use Onion\Framework\Loop\Descriptor;
use Onion\Framework\Loop\Interfaces\ResourceInterface;
use Onion\Framework\WebSocket\Events\DataEvent;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\RequestInterface;

use function Onion\Framework\Loop\coroutine;
use function Onion\Framework\Loop\tick;

use Psr\Http\Message\ResponseInterface;

class Client
{
    private const WEBSOCKET_KEY = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    public function __construct(
        private readonly EventDispatcherInterface $dispatcher,
    ) {
    }

    public function connect(RequestInterface $request)
    {
        $connection = HttpClient::send($request);
        $message = '';
        do {
            $message .= $connection->read(1);
        } while (preg_match('/\r?\n\r?\n$/i', $message) !== 1 && !$connection->eof());

        $headers = Message::parseResponse($message);

        if ($headers->getStatusCode() !== 101) {
            throw new \RuntimeException('Unable to connect');
        }

        if (!$headers->hasHeader('Upgrade') || $headers->getHeaderLine('upgrade') !== 'websocket') {
            throw new \RuntimeException("Missing or invalid Upgrade header");
        }

        if (!$headers->hasHeader('connection') || strtolower($headers->getHeaderLine('connection')) !== 'upgrade') {
            throw new \RuntimeException("Missing or invalid Connection header");
        }

        if (!$headers->hasHeader('Sec-WebSocket-Accept')) {
            throw new \RuntimeException('Missing Sec-WebSocket-Accept header');
        }

        $expectedChallenge = base64_encode(sha1($request->getHeaderLine('Sec-WebSocket-Key') . self::WEBSOCKET_KEY, true));

        if ($headers->getHeaderLine('Sec-WebSocket-Accept') !== $expectedChallenge) {
            throw new \RuntimeException('Sec-WebSocket-Accept did not match expected value');
        }

        $connection = new Descriptor($connection->detach());

        coroutine(function (
            ResourceInterface $connection,
            RequestInterface $request,
            ResponseInterface $response
        ) {
            while (!$connection->eof()) {
                $connection->wait();
                $this->dispatcher->dispatch(new DataEvent(
                    $request,
                    $response,
                    new WebSocket($connection)
                ));
                tick();
            }
        }, [$connection, $request, $headers]);

        return new WebSocket($connection);
    }
}
