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
                    new Frame(
                        data: $chunk,
                        type: $frame->getOpcode(),
                        final: $size === $index,
                        masked: $frame->isMasked(),
                        reserved1: $frame->reserved1,
                        reserved2: $frame->reserved2,
                        reserved3: $frame->reserved3,
                        length: strlen($chunk),
                    ),
                ),
            );
        }

        return $bytes;
    }

    public function read(): ?Frame
    {
        $this->resource->wait();
        return Frame::decode($this->resource);
    }

    public function getResource(): ResourceInterface
    {
        return $this->resource;
    }

    public function ping(string $text = '', $masked = false)
    {
        return $this->write(
            new Frame(data: $text, type: Types::PING, masked: $masked)
        ) !== 0;
    }

    public function pong(string $text = '', $masked = false)
    {
        return $this->write(new Frame(data: $text, type: Types::PONG, masked: $masked)) !== 0;
    }

    public function close(CloseReasons $code = CloseReasons::NORMAL, $masked = false)
    {
        return $this->write(new Frame(
            data: $code->value,
            type: Types::CLOSE,
            masked: $masked
        )) !== 0;
    }
}
