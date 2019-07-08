<?php
namespace Onion\Framework\WebSocket\Events;

use Onion\Framework\WebSocket\Stream as Resource;

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
