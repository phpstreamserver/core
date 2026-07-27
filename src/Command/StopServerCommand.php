<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Command;

use PHPStreamServer\Core\MessageBus\MessageInterface;

/**
 * Requests a graceful server shutdown.
 *
 * @implements MessageInterface<null>
 */
final readonly class StopServerCommand implements MessageInterface
{
    public function __construct(public int $code = 0)
    {
    }
}
