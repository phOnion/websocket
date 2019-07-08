<?php
namespace Onion\Framework\WebSocket\Events;

use Onion\Framework\Loop\Interfaces\ResourceInterface;
use Psr\Http\Message\ServerRequestInterface;

class ConnectEvent
{
    private $request;
    private $connection;

    public function __construct(ServerRequestInterface $request, ResourceInterface $resource)
    {
        $this->request = $request;
        $this->connection = $resource;
    }

    public function getRequest(): ServerRequestInterface
    {
        return $this->request;
    }

    public function getConnection(): ResourceInterface
    {
        return $this->connection;
    }
}
