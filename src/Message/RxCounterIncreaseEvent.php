<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Message;

use PHPStreamServer\Core\MessageBus\MessageInterface;

/**
 * @implements MessageInterface<null>
 */
final readonly class RxCounterIncreaseEvent implements MessageInterface
{
    public function __construct(
        public int $pid,
        public int $connectionId,
        public int $rx,
    ) {
    }
}
