<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Command;

use PHPStreamServer\Core\MessageBus\MessageInterface;
use PHPStreamServer\Core\Plugin\System\ProcessNetworkInfo;

/**
 * @implements MessageInterface<array<ProcessNetworkInfo>>
 */
final class GetProcessNetworkInfoCommand implements MessageInterface
{
}
