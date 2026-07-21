<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Message;

use PHPStreamServer\Core\MessageBus\MessageInterface;
use PHPStreamServer\Core\Plugin\Supervisor\ProcessInfo;

/**
 * @implements MessageInterface<array<ProcessInfo>>
 */
final class GetProcessesCommand implements MessageInterface
{
}
