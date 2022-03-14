<?php

declare(strict_types=1);

namespace Onion\Framework\WebSocket;

use Onion\Framework\Loop\Interfaces\ResourceInterface;
use Onion\Framework\WebSocket\Types\{Types, CloseReasons, Flags};
use RuntimeException;
use Stringable;

class Frame implements Stringable
{
    private const OPCODE_MASKED = 0b10000000;

    public function __construct(
        private readonly Types $type,
        private readonly bool $masked,
        private readonly bool $final = true,
        private readonly string $data = '',
        public readonly bool $reserved1 = false,
        public readonly bool $reserved2 = false,
        public readonly bool $reserved3 = false,
        public readonly int $length = 0,
    ) {
    }

    public function getOpcode(): Types
    {
        return $this->type;
    }

    public function isFinal(): bool
    {
        return $this->final;
    }

    public function getData(): string
    {
        return $this->data;
    }

    public function isMasked(): bool
    {
        return $this->masked;
    }

    public function getLength(): int
    {
        return $this->length;
    }

    public function withData(string $data): static
    {
        return new Frame(
            $this->getOpcode(),
            $this->isMasked(),
            $this->isFinal(),
            $data,
            $this->reserved1,
            $this->reserved2,
            $this->reserved3,
            strlen($data),
        );
    }

    public function __toString()
    {
        return $this->getData();
    }

    public static function create(
        array $header,
        ?string $data = '',
    ): Frame {
        return new Frame(
            type: Types::tryFrom($header[0] & Flags::OPCODE->value),
            masked: ($header[1] | static::OPCODE_MASKED) === $header[1],
            final: ($header[0] | Flags::FINISHED->value) === $header[0],
            reserved1: ($header[1] & Flags::RESERVED1->value) === Flags::RESERVED1->value,
            reserved2: ($header[1] & Flags::RESERVED2->value) === Flags::RESERVED2->value,
            reserved3: ($header[1] & Flags::RESERVED3->value) === Flags::RESERVED3->value,
            data: $data,
            length: (int) ($header[1] & Flags::LENGTH->value),
        );
    }

    public static function encode(Frame $frame): string
    {
        $data = $frame->getData();
        $length = strlen($data);
        $header = chr(($frame->isFinal() ? Flags::FINISHED->value : 0) | $frame->getOpcode()->value);

        $mask = $frame->isMasked() ? static::OPCODE_MASKED : 0b100000000;
        // Mask 0x80 | payload length (0-125)
        if ($length < 126) {
            $header .= chr($mask | $length);
        } elseif ($length < 0xFFFF) {
            $header .= chr($mask | 126) . pack("n", $length);
        } elseif (PHP_INT_SIZE > 4) { // 64 bit
            $header .= chr($mask | 127) . pack("Q", $length);
        } else { // 32 bit (pack Q dosen't work)
            $header .= chr($mask | 127) . pack("N", 0) . pack("N", $length);
        }

        if ($frame->isMasked()) {
            // Add mask
            $mask = pack("N", random_bytes(4));
            $header .= $mask;

            // Mask application data.
            for ($i = 0; $i < $length; $i++) {
                $data[$i] = chr(ord($data[$i]) ^ ord($mask[$i % 4]));
            }
        }

        return $header . $data;
    }

    public static function decode(ResourceInterface $resource): ?Frame
    {
        $header = $resource->read(2);
        if (strlen($header) !== 2) {
            return null;
        }

        $byte = [ord($header[0]), ord($header[1])];
        $frame = static::create($byte);

        $length = $frame->getLength();

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

        $contents = '';
        if ($frame->isMasked()) {
            $mask = $resource->read(4);
            for ($i = 0; $i < $length; $i++) {
                $ch = $resource->read(1);
                $contents[$i] = $ch ^ $mask[$i % 4];
            }
        } else {
            while (strlen($contents) < $length) {
                $contents .= $resource->read($length - strlen($contents));
            }
        }

        if ($frame->getOpcode() === Types::CLOSE) {
            $contents = (string) current(unpack('n', $contents));
        }

        return $frame->withData($contents);
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
