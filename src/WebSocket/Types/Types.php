<?php

namespace Onion\Framework\WebSocket\Types;

enum Types: int
{
case TEXT = 0x01;
case BINARY = 0x02;
case CLOSE = 0x08;
case PING = 0x09;
case PONG = 0x0A;
    }
