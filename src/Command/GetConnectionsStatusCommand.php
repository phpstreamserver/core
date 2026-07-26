<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Command;

use PHPStreamServer\Core\MessageBus\MessageInterface;
use PHPStreamServer\Core\Plugin\System\ConnectionsStatus;

/**
 * @implements MessageInterface<ConnectionsStatus>
 */
final class GetConnectionsStatusCommand implements MessageInterface
{
}
