<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Message;

use PHPStreamServer\Core\MessageBus\MessageInterface;
use PHPStreamServer\Core\Plugin\System\Connections\ConnectionsStatus;

/**
 * @implements MessageInterface<ConnectionsStatus>
 */
final class GetConnectionsStatusCommand implements MessageInterface
{
}
