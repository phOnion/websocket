<?php

namespace Onion\Framework\WebSocket\Events;

use Onion\Framework\WebSocket\WebSocket;
use Psr\Http\Message\ServerRequestInterface;

class ConnectEvent
{
    public function __construct(
        public readonly ServerRequestInterface $request,
        public readonly WebSocket $websocket,
    ) {
    }
}
