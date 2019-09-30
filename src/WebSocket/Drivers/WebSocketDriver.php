<?php
namespace Onion\Framework\WebSocket\Drivers;

use function Onion\Framework\Http\build_request;
use Onion\Framework\Http\Events\RequestEvent;
use Onion\Framework\Loop\Coroutine;
use Onion\Framework\Loop\Interfaces\ResourceInterface;
use Onion\Framework\Server\Drivers\DriverTrait;
use Onion\Framework\Server\Interfaces\ContextInterface;
use Onion\Framework\Server\Interfaces\DriverInterface;
use Onion\Framework\WebSocket\Events\HandshakeEvent;
use Psr\EventDispatcher\EventDispatcherInterface;

class WebSocketDriver implements DriverInterface
{
    protected const SOCKET_FLAGS = STREAM_SERVER_BIND | STREAM_SERVER_LISTEN;

    private $dispatcher;

    use DriverTrait;

    public function __construct(EventDispatcherInterface $dispatcher)
    {
        $this->dispatcher = $dispatcher;
    }

    public function getScheme(string $address): string
    {
        return 'tcp';
    }

    public function listen(string $address, ?int $port, ContextInterface ...$contexts): \Generator
    {
        $socket = self::createSocket($address, $port, $contexts);
        while ($socket->isAlive()) {
            yield $socket->wait();
            $connection = yield $socket->accept();

            yield $connection->wait();
            yield Coroutine::create(function (ResourceInterface $connection, EventDispatcherInterface $dispatcher) {
                $data = '';
                while ($connection->isAlive()) {
                    $chunk = $connection->read(8192);
                    if (!$chunk) {
                        break;
                    }

                    yield $data .= $chunk;
                }

                try {
                    $request = build_request($data);
                    if ($request->hasHeader('upgrade') && $request->getHeaderLine('upgrade') === 'websocket') {
                        yield $dispatcher->dispatch(new HandshakeEvent($request, $connection,));
                    } else {
                        yield $dispatcher->dispatch(
                            new RequestEvent($request, $connection)
                        );
                    }
                } catch (\InvalidArgumentException $ex) {
                    // Accept failed, we ok
                }
            }, [$connection, $this->dispatcher]);
        }
    }
}
