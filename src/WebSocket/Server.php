<?php
namespace Onion\Framework\WebSocket;

use function Onion\Framework\EventLoop\attach;
use function Onion\Framework\EventLoop\detach;
use function Onion\Framework\Server\build_request;
use GuzzleHttp\Stream\StreamInterface;
use Onion\Framework\Server\Server as Base;
use Onion\Framework\WebSocket\Exceptions\CloseException;
use Onion\Framework\WebSocket\Stream as WebSocket;

class Server extends Base
{
    public function __construct() {
        parent::on('receive', function (StreamInterface $stream) {
            try {
                $request = build_request($stream->getContents());

                if ($request->hasHeader('upgrade') && $request->getHeaderLine('upgrade') == 'websocket') {
                    $this->trigger('handshake', $request, $stream)
                        ->then(function(WebSocket $socket) use ($request) {
                            $resource = $socket->detach();

                            $this->trigger('open', $request);

                            attach($resource, function (StreamInterface $stream) {
                                $socket = new WebSocket($stream);

                                try {
                                    if(($data = $socket->read($this->getMaxPackageSize())) !== null) {
                                        $this->trigger('message', $socket, $data);
                                    }
                                } catch (CloseException $ex) {
                                    if (!$socket->isClosed()) {
                                        $socket->close($ex->getCode());
                                    }

                                    $stream = $socket->detach();

                                    detach($stream->detach());
                                    $stream->close();
                                    $this->trigger('close', $ex->getCode());
                                }
                            });
                        })->otherwise(function () {
                            $this->trigger('close', WebSocket::CODE_INTERNAL_ERROR);
                        });
                } else {
                    if ($request->getHeaderLine('Content-Length') > parent::getMaxPackageSize()) {
                        $promise = new Promise(function ($resolve) use ($request) {
                            $resolve(new Response($request->hasHeader('Expect') ? 417 : 413, [
                                'content-type' => 'text/plain',
                            ]));
                        });
                    } else {
                        $promise = $this->trigger('request', $request)
                            ->otherwise(function (\Throwable $ex) {
                                return new Response(500, [
                                    'Content-Type' => 'text/plain; charset=urf-8',
                                ], $ex->getMessage());
                            });
                    }

                    $promise->then(function (ResponseInterface $response) use ($stream) {
                        send_response($response, $stream);
                    })->finally(function () use ($stream) {
                        $stream->close();
                    });
                }
            } catch (\Throwable $ex) {
                $stream->close();
            }
        });
    }
}
