<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Command;

use PHPStreamServer\Core\MessageBus\AuthorizedSources;
use PHPStreamServer\Core\MessageBus\MessageInterface;
use PHPStreamServer\Core\MessageBus\MessageSource;

/**
 * Stops an on-demand worker by its worker ID
 *
 * @implements MessageInterface<null>
 */
#[AuthorizedSources(MessageSource::MASTER, MessageSource::CHILD)]
final readonly class StopWorkerCommand implements MessageInterface
{
    public function __construct(public int $workerId)
    {
    }
}
