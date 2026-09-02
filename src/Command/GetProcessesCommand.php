<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Command;

use PHPStreamServer\Core\MessageBus\AuthorizedSources;
use PHPStreamServer\Core\MessageBus\MessageInterface;
use PHPStreamServer\Core\MessageBus\MessageSource;
use PHPStreamServer\Core\Plugin\Supervisor\ProcessInfo;

/**
 * Retrieves metadata for processes managed by the supervisor.
 *
 * @implements MessageInterface<array<ProcessInfo>>
 */
#[AuthorizedSources(MessageSource::MASTER, MessageSource::CHILD, MessageSource::MANAGER)]
final class GetProcessesCommand implements MessageInterface
{
}
