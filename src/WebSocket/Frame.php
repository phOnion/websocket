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
        $data = $frame->getData();
        $length = strlen($data);
        $header = chr(($frame->isFinal() ? 0x80 : 0) | $frame->getOpcode()->value);

        // Mask 0x80 | payload length (0-125)
        if ($length < 126) $header .= chr(0x80 | $length);
        elseif ($length < 0xFFFF) $header .= chr(0x80 | 126) . pack("n", $length);
        elseif (PHP_INT_SIZE > 4) // 64 bit
            $header .= chr(0x80 | 127) . pack("Q", $length);
        else  // 32 bit (pack Q dosen't work)
            $header .= chr(0x80 | 127) . pack("N", 0) . pack("N", $length);

        // Add mask
        $mask = pack("N", random_bytes(4));
        $header .= $mask;

        // Mask application data.
        for ($i = 0; $i < $length; $i++)
            $data[$i] = chr(ord($data[$i]) ^ ord($mask[$i % 4]));

        return $header . $data;
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
                for ($i = 0; $i < $length; $i++) {
                    $ch = $resource->read(1);
                    $contents[$i] = $ch ^ $mask[$i % 4];
                }
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
