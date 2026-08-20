<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\MessageBus;

enum MessageSource
{
    case MASTER; // Master process
    case CHILD; // Worker process
    case EXTERNAL; // External proocess
}
