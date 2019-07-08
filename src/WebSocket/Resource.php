<?php
namespace Onion\Framework\WebSocket;

use Onion\Framework\Loop\Interfaces\ResourceInterface;
use Onion\Framework\WebSocket\Exceptions\CloseException;
use Onion\Framework\WebSocket\Exceptions\UnknownOpcodeException;

class Resource
{
    public const CODE_NORMAL_CLOSE = 1000;
    public const CODE_GOAWAY = 1001;
    public const CODE_PROTOCOL_ERROR = 1002;
    public const CODE_NOT_ACCEPTABLE = 1003;
    public const CODE_ABNORMAL_CLOSURE = 1006;
    public const CODE_INVALID_FRAME_DATA = 1007;
    public const CODE_MESSAGE_TOO_LONG = 1009;
    public const CODE_INTERNAL_ERROR = 1011;

    public const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    private $stream;

    private $mask = false;

    public function __construct(ResourceInterface $stream, bool $mask = false)
    {
        $this->stream = $stream;
        $this->mask = $mask;
    }

    public function write(Frame $frame): ?int
    {
        try {
            return $this->stream->write(Frame::encode($frame, $this->mask));
        } catch (\LogicException $ex) {
            throw new CloseException("Stream closed", self::CODE_ABNORMAL_CLOSURE, $ex);
        }
    }

    public function read(int $size = 8192): ?Frame
    {
        try {
            $data = $this->stream->read($size);
        } catch (\LogicException $ex) {
            throw new CloseException("Stream closed", self::CODE_ABNORMAL_CLOSURE, $ex);
        }

        if ($data === null) {
            return null;
        }

        try {
            $frame = Frame::unmask($data);
        } catch (\LengthException $ex) {
            throw new CloseException("Incomplete frame", self::CODE_INVALID_FRAME_DATA, $ex);
        }

        switch ($frame->getOpcode()) {
            case Frame::OPCODE_CLOSE:
                $status = $frame->getData();
                $code = self::CODE_ABNORMAL_CLOSURE;
                if ($status !== '') {
                    $code = current(unpack('n', $status, 0));
                }

                throw new CloseException("Received normal close signal", $code);
                break;
            case Frame::OPCODE_PING:
                $this->ping($frame->getData());
            case Frame::OPCODE_PONG:
                return null;
                break;
            case Frame::OPCODE_TEXT:
            case Frame::OPCODE_BINARY:
                return $frame;
            default:
                throw new UnknownOpcodeException(
                    "Unknown opcode received ({$frame->getOpcode()})",
                    self::CODE_NOT_ACCEPTABLE
                );
                break;
        }
    }

    public function ping(string $text = '')
    {
        return $this->write(
            new Frame($text, Frame::OPCODE_PING)
        ) > 0;
    }

    public function pong(string $text = '')
    {
        return $this->write(
            new Frame($text, Frame::OPCODE_PONG)
        ) > 0;
    }

    public function close(int $code = self::CODE_NORMAL_CLOSE): bool
    {
        return $this->write(
            new Frame(pack('n', $code), Frame::OPCODE_CLOSE)
        ) > 0;
    }

    public function __call($name, $arguments)
    {
        return $this->stream->{$name}(...$arguments);
    }
}
