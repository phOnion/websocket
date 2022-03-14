<?php

declare(strict_types=1);

namespace Onion\Framework\WebSocket\Events;

use Psr\EventDispatcher\StoppableEventInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Onion\Framework\WebSocket\WebSocket;

use function Onion\Framework\Loop\is_pending;

class DataEvent implements StoppableEventInterface
{
    public function __construct(
        public readonly RequestInterface $request,
        public readonly ResponseInterface $response,
        public readonly WebSocket $connection,
    ) {
    }

    public function isPropagationStopped(): bool
    {
        return !is_pending($this->connection->getResource());
    }
}
