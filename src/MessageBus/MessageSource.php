<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\MessageBus;

enum MessageSource
{
    case MASTER;   // Master process
    case CHILD;    // Child process
    case MANAGER;  // Authorized external process
    case EXTERNAL; // Other external process
}
