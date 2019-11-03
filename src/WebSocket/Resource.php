<?php
namespace Onion\Framework\WebSocket;

use Onion\Framework\Loop\Coroutine;
use Onion\Framework\Loop\Exceptions\DeadStreamException;
use Onion\Framework\Loop\Interfaces\AsyncResourceInterface;
use Onion\Framework\Loop\Interfaces\ResourceInterface;
use Onion\Framework\Loop\Interfaces\SchedulerInterface;
use Onion\Framework\Loop\Interfaces\TaskInterface;
use Onion\Framework\Loop\Signal;
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

    private const OPCODE_CONTINUATION = 0x00;
    private const OPCODE_FINISHED = 0b10000000;
    private const OPCODE_MASKED = 0b10000000;
    private const OPCODE_LENGTH = 0b01111111;
    private const OPCODE = 0b00001111;
    private const RESERVED = 0b01110000;

    public const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    private $stream;

    private $mask = false;

    public function __construct(ResourceInterface $stream, bool $mask = false)
    {
        $this->stream = $stream;
        $this->mask = $mask;
    }

    public function write(Frame $frame): Signal
    {
        return new Signal(function (TaskInterface $task, SchedulerInterface $scheduler) use ($frame) {
            $scheduler->add(new Coroutine(function ($task, $scheduler, $frame) {
                $chunks = str_split($frame->getData(), 65536);
                $bytesWritten = 0;
                $lastChunk = count($chunks)-1;
                try {
                    foreach ($chunks as $index => $chunk) {
                        yield $this->stream->wait(AsyncResourceInterface::OPERATION_WRITE);
                        $bytesWritten += @$this->stream->write(Frame::encode(
                            new Frame($chunk, $frame->getOpcode(), $index === $lastChunk)
                        , $this->mask));
                    }

                    $task->send($bytesWritten);
                } catch (\LogicException $ex) {
                    $task->throw(new CloseException("Stream closed", self::CODE_ABNORMAL_CLOSURE, $ex));
                }

                $scheduler->schedule($task);
            }, [$task, $scheduler, $frame]));
        });
    }

    public function read(): Signal
    {
        return new Signal(function (TaskInterface $task, SchedulerInterface $scheduler) {
            $scheduler->add(new Coroutine(function ($task, $scheduler) {
                try {
                    $finished = false;
                    $data = '';

                    do {
                        yield $this->stream->wait();

                        $header = str_split($this->stream->read(2), 1);
                        if (count($header) !== 2) {
                            return null;
                        }

                        $byte = [
                            ord($header[0]),
                            ord($header[1]),
                        ];

                        $finished = ($byte[0] | static::OPCODE_FINISHED) === $byte[0];
                        $masked = ($byte[1] & static::OPCODE_MASKED) ? true : false;
                        $length = (int) ($byte[1] & static::OPCODE_LENGTH);

                        if ($length === 126) {
                            $length = \unpack('n', $this->stream->read(2), 0)[1];
                        } elseif ($length === 127) {
                            $lp = \unpack('N2', $this->stream->read(8), 0);
                            if (\PHP_INT_MAX === 0x7FFFFFFF) {
                                if ($lp[1] !== 0 || $lp[2] < 0) {
                                    throw new \OutOfBoundsException(
                                        'Max payload size exceeded',
                                        Frame::MESSAGE_TOO_BIG
                                    );
                                }
                                $length = $lp[2];
                            } else {
                                $length = $lp[1] << 32 | $lp[2];
                                if ($length < 0) {
                                    throw new WebSocketException(
                                        'Cannot use most significant bit in 64 bit length field',
                                        Frame::MESSAGE_TOO_BIG
                                    );
                                }
                            }
                        }

                        if ($length < 0) {
                            throw new WebSocketException(
                                'Payload length must not be negative',
                                Frame::MESSAGE_TOO_BIG
                            );
                        }

                        if ($masked) {
                            $mask = $this->stream->read(4);
                            $data .= $length ?
                                (\str_pad($mask, $length, $mask, \STR_PAD_RIGHT) ^ $this->stream->read($length)) : '';
                        } else {
                            $data .= $length ? $this->stream->read($length) : '';
                        }

                    } while (!$finished);
                } catch (DeadStreamException $ex) {
                    yield $this->close(self::CODE_ABNORMAL_CLOSURE);
                    $task->throw(new CloseException("Stream closed", self::CODE_ABNORMAL_CLOSURE, $ex));
                }

                $frame = new Frame($data, $byte[0] & static::OPCODE, $finished);
                switch ($frame->getOpcode()) {
                    case Frame::OPCODE_CLOSE:
                        $status = $frame->getData();
                        $code = self::CODE_ABNORMAL_CLOSURE;
                        if ($status !== '') {
                            $code = current(unpack('n', $status, 0));
                        }

                        yield $this->close($code);
                        $task->throw(new CloseException("Received normal close signal", $code));
                        break;
                    case Frame::OPCODE_PING:
                        yield $this->pong($frame->getData());
                    case Frame::OPCODE_PONG:
                        $task->kill();
                        break;
                    case Frame::OPCODE_TEXT:
                    case Frame::OPCODE_BINARY:
                        $task->send($frame);
                        break;
                    default:
                        yield $this->close(self::CODE_NOT_ACCEPTABLE);
                        $task->throw(new UnknownOpcodeException(
                            "Unknown opcode received ({$frame->getOpcode()})",
                            self::CODE_NOT_ACCEPTABLE
                        ));
                        break;
                }

                $scheduler->schedule($task);
            }, [$task, $scheduler]));
        });
    }

    public function ping(string $text = ''): Signal
    {
        return $this->write(
            new Frame($text, Frame::OPCODE_PING)
        );
    }

    public function pong(string $text = ''): Signal
    {
        return $this->write(
            new Frame($text, Frame::OPCODE_PONG)
        );
    }

    public function close(int $code = self::CODE_NORMAL_CLOSE): Signal
    {
        return $this->write(
            new Frame(pack('n', $code), Frame::OPCODE_CLOSE)
        );
    }

    public function __call($name, $arguments)
    {
        return $this->stream->{$name}(...$arguments);
    }
}
