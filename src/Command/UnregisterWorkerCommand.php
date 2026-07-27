<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Command;

use PHPStreamServer\Core\MessageBus\MessageInterface;

/**
 * Unregisters a worker using its assigned ID.
 *
 * @implements MessageInterface<null>
 */
final readonly class UnregisterWorkerCommand implements MessageInterface
{
    public function __construct(public int $workerId)
    {
    }
}
