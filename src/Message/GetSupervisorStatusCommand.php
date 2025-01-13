<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Message;

use PHPStreamServer\Core\MessageBus\MessageInterface;
use PHPStreamServer\Core\Plugin\Supervisor\Status\SupervisorStatus;

/**
 * @implements MessageInterface<SupervisorStatus>
 */
final class GetSupervisorStatusCommand implements MessageInterface
{
}
