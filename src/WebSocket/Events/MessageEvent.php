<?php
namespace Onion\Framework\WebSocket\Events;

use Onion\Framework\WebSocket\Resource;
use Psr\Http\Message\ServerRequestInterface;

class MessageEvent
{
    private $connection;
    private $request;

    public function __construct(Resource $resource, ServerRequestInterface $request)
    {
        $this->connection = $resource;
        $this->request = $request;
    }

    public function getConnection(): Resource
    {
        return $this->connection;
    }

    public function getRequest(): ServerRequestInterface
    {
        return $this->request;
    }
}
