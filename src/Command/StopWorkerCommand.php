<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Command;

use PHPStreamServer\Core\MessageBus\MessageInterface;

/**
 * Stops an on-demand worker by its worker ID
 *
 * @implements MessageInterface<null>
 */
final readonly class StopWorkerCommand implements MessageInterface
{
    public function __construct(public int $workerId)
    {
    }
}
