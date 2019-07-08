<?php
namespace Onion\Framework\WebSocket\Events;

use Onion\Framework\Loop\Interfaces\ResourceInterface;
use Psr\Http\Message\ServerRequestInterface;

class CloseEvent
{
    private $request;
    private $connection;
    private $code;

    public function __construct(ServerRequestInterface $request, ResourceInterface $resource, int $code)
    {
        $this->request = $request;
        $this->connection = $resource;
        $this->code = $code;
    }

    public function getRequest(): ServerRequestInterface
    {
        return $this->request;
    }

    public function getConnection(): ResourceInterface
    {
        return $this->connection;
    }

    public function getCode(): int
    {
        return $this->code;
    }
}
