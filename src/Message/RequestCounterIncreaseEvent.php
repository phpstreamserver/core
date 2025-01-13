<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Message;

use PHPStreamServer\Core\MessageBus\MessageInterface;

/**
 * @implements MessageInterface<null>
 */
final readonly class RequestCounterIncreaseEvent implements MessageInterface
{
    public function __construct(
        public int $pid,
        public int $requests,
    ) {
    }
}
