<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Event;

use PHPStreamServer\Core\MessageBus\AuthorizedSources;
use PHPStreamServer\Core\MessageBus\MessageInterface;
use PHPStreamServer\Core\MessageBus\MessageSource;

/**
 * A process sends this message periodically.
 *
 * @implements MessageInterface<null>
 */
#[AuthorizedSources(MessageSource::CHILD)]
final readonly class ProcessHeartbeatEvent implements MessageInterface
{
    public function __construct(
        public int $pid,
        public int $memory,
        public int $time,
    ) {
    }
}
