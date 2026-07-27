<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Command;

use PHPStreamServer\Core\MessageBus\MessageInterface;
use PHPStreamServer\Core\Plugin\System\ProcessNetworkInfo;

/**
 * Retrieves network traffic and active connection information for worker processes.
 *
 * @implements MessageInterface<array<ProcessNetworkInfo>>
 */
final class GetProcessNetworkInfoCommand implements MessageInterface
{
}
