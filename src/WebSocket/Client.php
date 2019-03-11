<?php
namespace Onion\Framework\WebSocket;

use Closure;
use function GuzzleHttp\Psr7\parse_request;
use function GuzzleHttp\Psr7\parse_response;
use function GuzzleHttp\Psr7\str;
use function GuzzleHttp\Psr7\uri_for;
use function Onion\Framework\EventLoop\attach;
use function Onion\Framework\EventLoop\detach;
use function Onion\Framework\Promise\async;
use GuzzleHttp\Psr7\Request;
use Onion\Framework\EventLoop\Stream\Interfaces\StreamInterface;
use Onion\Framework\EventLoop\Stream\Stream as RawStream;
use Onion\Framework\Promise\Interfaces\PromiseInterface;
use Onion\Framework\Promise\Promise;
use Onion\Framework\WebSocket\Stream;

class Client
{
    private $uri;
    private $timeout;
    private $flags;
    private $options;

    public function __construct(string $uri, int $timeout = 1, int $flags = STREAM_CLIENT_CONNECT)
    {
        $this->uri = uri_for($uri);
        $this->timeout = $timeout;
        $this->flags = $flags;
    }

    public function set(array $options)
    {
        $this->options = $options;
    }

    private function handshake($resource, array $headers = []): Stream
    {
        $nonce = \base64_encode(\random_bytes(16));
        $request = (new Request('GET', $this->uri, $headers))
            ->withHeader('Sec-WebSocket-Key', $nonce)
            ->withHeader('Upgrade', 'websocket')
            ->withHeader('Sec-WebSocket-Version', '13');

        $message = str($request);

        $stream = new RawStream($resource);
        $stream->write($message);
        $response = parse_response($stream->read(1024 * 1024 * 2));

        if ($response->getHeaderLine('Upgrade') !== 'websocket') {
            throw new \RuntimeException(
                'Unable to negotiate protocol'
            );
        }

        if ($response->getStatusCode() !== 101) {
            throw new \RuntimeException(
                "Upgrade request failed ({$response->getStatusCode()} - {$response->getReasonPhrase()}"
            );
        }

        $challenge = \base64_encode(\sha1($nonce . Stream::GUID, true));
        if ($response->getHeaderLine('Sec-WebSocket-Accept') !== $challenge) {
            throw new \RuntimeException('Negotiation challenge failed');
        }

        return new Stream($stream, true);
    }

    public function connect(array $headers = []): PromiseInterface
    {
        return async(function () use ($headers) {
            $resource = fsockopen(
                $this->uri->getHost(),
                $this->uri->getPort(),
                $error,
                $message,
                $this->timeout
            );

            if (!$resource) {
                throw new \RuntimeException($message, $error);
            }
            stream_set_blocking($resource, 0);

            return $this->handshake($resource, $headers);
        });
    }
}
