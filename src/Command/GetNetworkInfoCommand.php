<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Command;

use PHPStreamServer\Core\MessageBus\AuthorizedSources;
use PHPStreamServer\Core\MessageBus\MessageInterface;
use PHPStreamServer\Core\MessageBus\MessageSource;
use PHPStreamServer\Core\Plugin\System\ProcessNetworkInfo;

/**
 * Retrieves network traffic and active connection information for worker processes.
 *
 * @implements MessageInterface<array<ProcessNetworkInfo>>
 */
#[AuthorizedSources(MessageSource::MASTER, MessageSource::MANAGER)]
final class GetNetworkInfoCommand implements MessageInterface
{
}
