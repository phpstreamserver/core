<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Command;

use PHPStreamServer\Core\MessageBus\MessageInterface;
use PHPStreamServer\Core\Plugin\System\ServerStatus;

/**
 * Retrieves runtime information about the running server.
 *
 * @implements MessageInterface<ServerStatus>
 */
final class GetServerStatusCommand implements MessageInterface
{
}
