<?php
namespace Onion\Framework\WebSocket;

class Frame
{
    public const OPCODE_TEXT = 0x01;
    public const OPCODE_BINARY = 0x02;
    public const OPCODE_CLOSE = 0x08;
    public const OPCODE_PING = 0x09;
    public const OPCODE_PONG = 0x0A;

    private const OPCODE_CONTINUATION = 0x00;
    private const OPCODE_FINISHED = 0b10000000;
    private const OPCODE_MASKED = 0b10000000;
    private const OPCODE_LENGTH = 0b01111111;
    private const OPCODE = 0b00001111;
    private const RESERVED = 0b01110000;

    private const OPCODE_READABLE_MAP = [
        0 => 'UNKNOWN',
        0x01 => 'TEXT (0x01)',
        0x02 => 'BINARY (0x02)',
        0x08 => 'CLOSE (0x08)',
        0x09 => 'PING (0x09)',
        0x0A => 'PONG (0x0A)',
    ];

    private $data;
    private $opcode = -1;
    private $final;

    public function __construct(?string $data = null, int $opcode = self::OPCODE_TEXT, bool $final = true)
    {
        $this->data = $data;
        $this->opcode = $opcode;
        $this->final = $final;
    }

    public function getOpcode(): int
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

    public function __toString()
    {
        return $this->getData();
    }

    public static function encode(Frame $frame, bool $masked = false): string
    {
        $length = strlen($frame->getData());

        $header = chr($frame->getOpcode() |
            ($frame->isFinal() ? Frame::OPCODE_FINISHED : Frame::OPCODE_CONTINUATION) |
            ($masked ? self::RESERVED : 0));

        $mask = $masked ? self::OPCODE_MASKED : 0;

        if ($length > 65536) {
            $header .= pack('CNN', $mask | 127, $length, $length << 32);
        } elseif ($length > 125) {
            $header .= pack('Cn', $mask | 126, $length);
        } else {
            $header .= chr($mask | $length);
        }

        if (!$masked) {
            return $header . $frame->getData();
        }

        $bytes = \random_bytes(4);
        return $header . $bytes . ($frame->getData() ^ \str_pad($bytes, $length, $bytes, \STR_PAD_RIGHT));
    }

    public static function unmask(string $packet): Frame
    {
        $fp = fopen('php://temp', 'w+b');
        fwrite($fp, $packet);
        rewind($fp);

        $header = fread($fp, 2);
        $byte = [
            ord($header[0]),
            ord($header[1]),
        ];

        $finished = ($byte[0] | Frame::OPCODE_FINISHED) === $byte[0];
        $masked = ($byte[1] & self::OPCODE_MASKED) ? true : false;
        $length = $byte[1] & self::OPCODE_LENGTH;

        if ($length === 126) {
            $length = \unpack('n', fread($fp, 2), 0)[1];
        } elseif ($length === 127) {
            $lp = \unpack('N2', fread($fp, 8), 0);
            if (\PHP_INT_MAX === 0x7FFFFFFF) {
                if ($lp[1] !== 0 || $lp[2] < 0) {
                    throw new \OutOfBoundsException('Max payload size exceeded', Frame::MESSAGE_TOO_BIG);
                }
                $length = $lp[2];
            } else {
                $length = $lp[1] << 32 | $lp[2];
                if ($length < 0) {
                    throw new WebSocketException('Cannot use most significant bit in 64 bit length field', Frame::MESSAGE_TOO_BIG);
                }
            }
        }

        if ($length < 0) {
            throw new WebSocketException('Payload length must not be negative', Frame::MESSAGE_TOO_BIG);
        }

        if ($masked) {
            $mask = fread($fp, 4);
            $data = $length ? (\str_pad($mask, $length, $mask, \STR_PAD_RIGHT) ^ fread($fp, $length)) : '';
        } else {
            $data = $length ? fread($fp, $length) : '';
        }

        return new Frame(
            $data,
            $byte[0] & self::OPCODE,
            $finished
        );
    }

    public function __debugInfo()
    {
        return [
            'opcode' => self::OPCODE_READABLE_MAP[$this->getOpcode()],
            'final' => $this->isFinal() ? 'Yes' : 'No',
            'size' => \strlen($this->getData()),
        ];
    }
}
