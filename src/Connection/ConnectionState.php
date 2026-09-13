<?php

declare(strict_types=1);

namespace PhpMiniCache\Connection;

enum ConnectionState
{
    case New;
    case Connected;
    case Reading;
    case Processing;
    case Writing;
    case Closed;
}
