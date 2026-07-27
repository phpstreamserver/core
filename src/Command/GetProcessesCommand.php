<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Command;

use PHPStreamServer\Core\MessageBus\MessageInterface;
use PHPStreamServer\Core\Plugin\Supervisor\ProcessInfo;

/**
 * Retrieves metadata for processes managed by the supervisor.
 *
 * @implements MessageInterface<array<ProcessInfo>>
 */
final class GetProcessesCommand implements MessageInterface
{
}
