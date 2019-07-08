<?php
namespace Onion\Framework\WebSocket\Drivers;

use function Onion\Framework\Http\build_request;
use Onion\Framework\Http\Events\RequestEvent;
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
            try {
                $connection = $socket->accept();
            } catch (\InvalidArgumentException $ex) {
                // Accept failed, we ok
                continue;
            }

            yield $connection->wait();
            $data = '';
            while ($connection->isAlive()) {
                $chunk = $connection->read(8192);
                if (!$chunk) {
                    break;
                }

                $data .= $chunk;
            }

            $request = build_request($data);
            if ($request->hasHeader('upgrade') && $request->getHeaderLine('upgrade') === 'websocket') {
                yield $this->dispatcher->dispatch(new HandshakeEvent($request, $connection,));
            } else {
                yield $this->dispatcher->dispatch(
                    new RequestEvent($request, $connection)
                );
            }
        }
    }
}
