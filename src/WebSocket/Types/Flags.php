<?php

namespace Onion\Framework\WebSocket\Types;

enum Flags: int
{
case CONTINUATION = 0x00;
case FINISHED = 0b10000000;
case LENGTH = 0b01111111;
case OPCODE = 0b00001111;
case RESERVED = 0b01110000;
    }
