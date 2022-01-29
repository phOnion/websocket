<?php

namespace Onion\Framework\WebSocket\Events;

use Psr\EventDispatcher\StoppableEventInterface;
use Onion\Framework\WebSocket\Frame;
use Psr\Http\Message\ServerRequestInterface;

class MessageEvent implements StoppableEventInterface
{
    private ?Frame $frame = null;

    public function __construct(
        public readonly ServerRequestInterface $request,
        public readonly Frame $message,
    ) {
    }

    public function setResponse(Frame $frame): void
    {
        $this->frame = $frame;
    }

    public function getResponse(): ?Frame
    {
        return $this->frame;
    }

    public function isPropagationStopped(): bool
    {
        return $this->frame === null;
    }
}
