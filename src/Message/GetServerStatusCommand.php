<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Message;

use PHPStreamServer\Core\MessageBus\MessageInterface;
use PHPStreamServer\Core\Plugin\System\Status\ServerStatus;

/**
 * @implements MessageInterface<ServerStatus>
 */
final class GetServerStatusCommand implements MessageInterface
{
}
