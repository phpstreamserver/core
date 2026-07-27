<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Command;

use PHPStreamServer\Core\MessageBus\MessageInterface;

/**
 * Requests a server reload.
 *
 * @implements MessageInterface<null>
 */
final readonly class ReloadServerCommand implements MessageInterface
{
    public function __construct()
    {
    }
}
