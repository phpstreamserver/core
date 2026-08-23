<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Command;

use PHPStreamServer\Core\MessageBus\AuthorizedSources;
use PHPStreamServer\Core\MessageBus\MessageInterface;
use PHPStreamServer\Core\MessageBus\MessageSource;

/**
 * Requests a graceful server shutdown.
 *
 * @implements MessageInterface<null>
 */
#[AuthorizedSources(MessageSource::MASTER, MessageSource::MANAGER)]
final readonly class StopServerCommand implements MessageInterface
{
    public function __construct(public int $code = 0)
    {
    }
}
