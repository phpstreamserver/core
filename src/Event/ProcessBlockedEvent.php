<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Event;

use PHPStreamServer\Core\MessageBus\AuthorizedSources;
use PHPStreamServer\Core\MessageBus\MessageInterface;
use PHPStreamServer\Core\MessageBus\MessageSource;

/**
 * Process blocked by IO operations
 * @implements MessageInterface<null>
 */
#[AuthorizedSources(MessageSource::MASTER)]
final readonly class ProcessBlockedEvent implements MessageInterface
{
    public function __construct(
        public int $pid,
    ) {
    }
}
