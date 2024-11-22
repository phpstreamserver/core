<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Plugin\System\Message;

use PHPStreamServer\Core\MessageBus\MessageInterface;

/**
 * @implements MessageInterface<null>
 */
final readonly class ConnectionClosedEvent implements MessageInterface
{
    public function __construct(
        public int $pid,
        public int $connectionId,
    ) {
    }
}
