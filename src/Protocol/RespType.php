<?php

declare(strict_types=1);

namespace PhpMiniCache\Protocol;

enum RespType
{
    case SimpleString;
    case Error;
    case Integer;
    case BulkString;
    case Array;
}
