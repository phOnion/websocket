<?php

namespace Onion\Framework\WebSocket\Events;

use Onion\Framework\WebSocket\WebSocket;
use Psr\Http\Message\ServerRequestInterface;

class CloseEvent
{
    public function __construct(
        private readonly ServerRequestInterface $request,
        private readonly WebSocket $websocket,
        private readonly int $code,
    ) {
    }

    public function getRequest(): ServerRequestInterface
    {
        return $this->request;
    }

    public function getConnection(): WebSocket
    {
        return $this->websocket;
    }

    public function getCode(): int
    {
        return $this->code;
    }
}
