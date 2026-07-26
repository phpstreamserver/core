<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Command;

use PHPStreamServer\Core\MessageBus\MessageInterface;
use PHPStreamServer\Core\Plugin\Supervisor\WorkerInfo;

/**
 * @implements MessageInterface<array<WorkerInfo>>
 */
final class GetWorkersCommand implements MessageInterface
{
}
