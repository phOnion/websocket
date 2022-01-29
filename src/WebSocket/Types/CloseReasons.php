<?php

namespace Onion\Framework\WebSocket\Types;

enum CloseReasons: int
{
case NORMAL = 1000;
case GO_AWAY = 1001;
case PROTOCOL_ERROR = 1002;
case NOT_ACCEPTABLE = 1003;
case NO_CODE = 1005;
case ABNORMAL_CLOSURE = 1006;
case INVALID_FRAME_DATA = 1007;
case POLICY_VIOLATION = 1008;
case MESSAGE_TOO_LONG = 1009;
case EXTENSION_NEGOTIATION = 1010;
case INTERNAL_ERROR = 1011;
    }
