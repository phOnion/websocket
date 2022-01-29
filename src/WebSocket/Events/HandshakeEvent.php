<?php

namespace Onion\Framework\WebSocket\Events;

use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};

class HandshakeEvent
{
    public function __construct(
        public readonly ServerRequestInterface $request,
        private ResponseInterface $response,
    ) {
    }

    public function getResponse(): ResponseInterface
    {
        return $this->response;
    }

    public function setResponse(ResponseInterface $response): void
    {
        $this->response = $response;
    }
}
