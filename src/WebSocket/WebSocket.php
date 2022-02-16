<?php

namespace Onion\Framework\WebSocket;

use Onion\Framework\Loop\Interfaces\ResourceInterface;
use Onion\Framework\Loop\Types\Operation;
use Onion\Framework\WebSocket\Types\{CloseReasons, Types};

class WebSocket
{
    public function __construct(private readonly ResourceInterface $resource)
    {
    }

    public function write(Frame $frame): int
    {
        $chunks = str_split($frame->getData(), 65536);
        $bytes = 0;
        $size = count($chunks) - 1;

        foreach ($chunks as $index => $chunk) {
            $this->resource->wait(Operation::WRITE);
            $bytes += $this->resource->write(
                Frame::encode(
                    new Frame($chunk, $frame->getOpcode(), $size === $index),
                ),
            );
        }

        return $bytes;
    }

    public function read(): ?Frame
    {
        return Frame::decode($this->resource);
    }

    public function getResource(): ResourceInterface
    {
        return $this->resource;
    }

    public function ping(string $text = '')
    {
        return $this->write(
            new Frame($text, Types::PING)
        ) !== 0;
    }

    public function pong(string $text = '')
    {
        return $this->write(new Frame($text, Types::PONG)) !== 0;
    }

    public function close(CloseReasons $code = CloseReasons::NORMAL)
    {
        return $this->write(new Frame(
            pack('n', $code->value),
            Types::CLOSE
        )) !== 0;
    }
}