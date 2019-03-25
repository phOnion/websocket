<?php
namespace Onion\Framework\WebSocket\Handlers;

use GuzzleHttp\Stream\StreamInterface;
use Onion\Framework\WebSocket\Stream as WebSocket;
use Psr\Http\Message\ServerRequestInterface;

class Handshake
{
    private $protocols = [];

    public function __construct(array $protocols = [])
    {
        $this->protocols = $protocols;
    }

    public function __invoke(ServerRequestInterface $request, StreamInterface $stream)
    {
        $secWebSocketKey = $request->getHeaderLine('sec-websocket-key');
        $pattern = '#^[+/0-9A-Za-z]{21}[AQgw]==$#';

        if (0 === preg_match($pattern, $secWebSocketKey) || 16 !== strlen(base64_decode($secWebSocketKey))) {
            $stream->close();
            return false;
        }

        $key = base64_encode(sha1(
            $request->getHeaderLine('sec-websocket-key') . WebSocket::GUID,
            true
        ));

        $stream->write("HTTP/1.1 101 Switching Protocols\n");
        $stream->write("Upgrade: websocket\n");
        $stream->write("Connection: Upgrade\n");
        $stream->write("Sec-WebSocket-Accept: {$key}\n");
        if ($request->hasHeader('Sec-WebSocket-Protocol')) {
            $stream->write("Sec-WebSocket-Protocol: {$request->getHeaderLine('sec-websocket-protocol')}\n");
        }

        if ($request->hasHeader('Sec-WebSocket-Protocol')) {
            foreach ($request->getHeader('Sec-WebSocket-Protocol') as $accept) {
                if (in_array(strtolower($accept), $this->protocols, true)) {
                    $stream->write("Sec-WebSocket-Protocol: {$accept}\n");
                    break;
                }
            }
        }
        $stream->write("Sec-WebSocket-Version: 13\n\n");

        return new WebSocket($stream);
    }
}
