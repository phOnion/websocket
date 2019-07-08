<?php
namespace Onion\Framework\WebSocket\Events;

use Onion\Framework\WebSocket\Resource;

class MessageEvent
{
    private $connection;

    public function __construct(Resource $resource)
    {
        $this->connection = $resource;
    }

    public function getConnection(): Resource
    {
        return $this->connection;
    }
}
