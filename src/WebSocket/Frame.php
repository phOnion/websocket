<?php

namespace Onion\Framework\WebSocket;

use Onion\Framework\Loop\Interfaces\ResourceInterface;
use Onion\Framework\WebSocket\Types\{Types, CloseReasons, Flags};
use RuntimeException;
use Stringable;

use function Onion\Framework\Loop\tick;

class Frame implements Stringable
{
    private const OPCODE_MASKED = 0b10000000;

    public function __construct(
        private readonly ?string $data = null,
        private readonly Types $opcode = Types::TEXT,
        private readonly bool $final = true,
        private readonly bool $masked = false,
    ) {
    }

    public function getOpcode(): Types
    {
        return $this->opcode;
    }

    public function isFinal(): bool
    {
        return $this->final === true;
    }

    public function getData(): string
    {
        return (string) $this->data;
    }

    public function isMasked(): bool
    {
        return $this->masked;
    }

    public function __toString()
    {
        return $this->getData();
    }

    public static function encode(Frame $frame): string
    {
        $length = strlen($frame->getData());

        $header = chr($frame->getOpcode()->value |
            ($frame->isFinal() ? Flags::FINISHED : Flags::CONTINUATION)->value |
            ($frame->isMasked() ? Flags::RESERVED->value : 0));

        $mask = $frame->isMasked() ? self::OPCODE_MASKED : 0;

        if ($length > 65536) {
            $header .= pack('CNN', $mask | 127, $length, $length << 32);
        } elseif ($length > 125) {
            $header .= pack('Cn', $mask | 126, $length);
        } else {
            $header .= chr($mask | $length);
        }

        if (!$frame->isMasked()) {
            return $header . $frame->getData();
        }

        $bytes = \random_bytes(4);
        return $header . $bytes . ($frame->getData() ^ \str_pad($bytes, $length, $bytes, \STR_PAD_RIGHT));
    }

    public static function decode(ResourceInterface $resource): ?Frame
    {
        $byte = [Types::TEXT->value];
        $contents = '';
        $finished = true;
        $masked = false;

        do {
            $h = $resource->read(2);
            if (strlen($h) !== 2) {
                return null;
            }

            $header = [$h[0], $h[1]];

            $byte = [ord($header[0]), ord($header[1])];
            $finished = ($byte[0] | Flags::FINISHED->value) === $byte[0];
            $masked = ($byte[1] & static::OPCODE_MASKED) ? true : false;
            $length = (int) $byte[1] & Flags::LENGTH->value;

            if ($length === 126) {
                $length = unpack('n', $resource->read(2), 0)[1];
            } elseif ($length === 127) {
                $lp = \unpack('N2', $resource->read(8))[0];

                if (PHP_INT_MAX === 0x7FFFFFFF) {
                    if ($lp[1] !== 0 || $lp[2] < 0) {
                        throw new \OutOfBoundsException(
                            'Max payload size exceeded',
                            CloseReasons::MESSAGE_TOO_LONG->value
                        );
                    }
                    $length = $lp[2];
                } else {
                    $length = $lp[1] << 32 | $lp[2];
                }
            }

            if ($length < 0) {
                throw new RuntimeException(
                    'Payload length resulted in negative',
                    CloseReasons::INVALID_FRAME_DATA->value
                );
            }

            if ($masked) {
                $mask = $resource->read(4);
                $contents .= $length ?
                    str_pad($mask, $length, $masked . STR_PAD_RIGHT) ^ $resource->read($length) : '';
            } else {
                $contents .= $length ? $resource->read($length) : '';
            }

            tick();
        } while (!$finished);

        return new Frame(
            $contents,
            Types::from($byte[0] & Flags::OPCODE->value),
            $finished,
            $masked,
        );
    }

    public function __debugInfo()
    {
        return [
            'opcode' => match ($this->getOpcode()) {
                Types::TEXT => 'TEXT (0x01)',
                Types::BINARY => 'BINARY (0x02)',
                Types::CLOSE => 'CLOSE (0x08)',
                Types::PING => 'PING (0x09)',
                Types::PONG => 'PONG (0x0A)',
                default => 'UNKNOWN',
            },
            'content' => $this->data,
            'final' => $this->isFinal(),
            'size' => \strlen($this->getData()),
        ];
    }
}
