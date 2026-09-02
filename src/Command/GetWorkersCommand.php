<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Command;

use PHPStreamServer\Core\MessageBus\AuthorizedSources;
use PHPStreamServer\Core\MessageBus\MessageInterface;
use PHPStreamServer\Core\MessageBus\MessageSource;
use PHPStreamServer\Core\Plugin\Supervisor\WorkerInfo;

/**
 * Retrieves metadata for workers registered with the supervisor.
 *
 * @implements MessageInterface<array<WorkerInfo>>
 */
#[AuthorizedSources(MessageSource::MASTER, MessageSource::CHILD, MessageSource::MANAGER)]
final class GetWorkersCommand implements MessageInterface
{
}
