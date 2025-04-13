<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Message;

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

    public function __serialize(): array
    {
        return [
            0 => $this->pid,
            1 => $this->connectionId,
        ];
    }

    public function __unserialize(array $data): void
    {
        $this->pid = $data[0];
        $this->connectionId = $data[1];
    }
}
