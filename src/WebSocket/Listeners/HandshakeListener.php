<?php
namespace Onion\Framework\WebSocket\Listeners;

use function GuzzleHttp\Psr7\str;
use GuzzleHttp\Psr7\Response;
use Onion\Framework\WebSocket\Events\CloseEvent;
use Onion\Framework\WebSocket\Events\ConnectEvent;
use Onion\Framework\WebSocket\Events\HandshakeEvent;
use Onion\Framework\WebSocket\Events\MessageEvent;
use Onion\Framework\WebSocket\Exceptions\CloseException;
use Onion\Framework\WebSocket\Stream as Resource;
use Psr\EventDispatcher\EventDispatcherInterface;

class HandshakeListener
{
    private const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
    private const KEY_PATTERN = '#^[+/0-9A-Za-z]{21}[AQgw]==$#';

    private $dispatcher;
    private $protocols = [];

    public function __construct(EventDispatcherInterface $dispatcher, array $websocketProtocols = [])
    {
        $this->dispatcher = $dispatcher;
        $this->protocols = $websocketProtocols;
    }

    public function __invoke(HandshakeEvent $event)
    {
        $request = $event->getRequest();
        $connection = $event->getConnection();

        $challenge = $request->getHeaderLine('sec-websocket-key');
        if (!$this->isWebsocketKeyValid($challenge)) {
            $connection->close();
            echo 'Nope!';
            return;
        }

        $key = $this->buildChallengeResponse($challenge);

        $response = new Response(101, [
            'Upgrade' => 'websocket',
            'Connection' => 'Upgrade',
            'Sec-WebSocket-Accept' => $key,
            'Sec-WebSocket-Version' => '13',
        ]);

        if ($request->hasHeader('Sec-WebSocket-Protocol')) {
            $response = $response->withAddedHeader(
                'Sec-WebSocket-Protocol',
                array_intersect($this->protocols, $request->getHeader('Sec-Websocket-Protocol'))
            );
        }

        $connection->write(str($response));

        yield $this->dispatcher->dispatch(new ConnectEvent($request, $connection));

        while ($connection->isAlive()) {
            yield $connection->wait();
            $ws = new Resource($connection);

            try {
                yield $this->dispatcher->dispatch(new MessageEvent($ws));
            } catch (CloseException $ex) {
                $ws->close($ex->getCode());
                yield $this->dispatcher->dispatch(new CloseEvent($request, $connection, $ex->getCode()));
                break;
            }
        }
    }

    private function isWebsocketKeyValid(string $key)
    {
        return preg_match(static::KEY_PATTERN, $key) !== 0 &&
            strlen(base64_decode($key)) === 16;
    }

    private function buildChallengeResponse(string $key)
    {
        return base64_encode(sha1($key . static::GUID, true));
    }
}
