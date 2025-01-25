<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Internal;

enum Status
{
    case SHUTDOWN;
    case STARTING;
    case RUNNING;
    case STOPPING;
}
