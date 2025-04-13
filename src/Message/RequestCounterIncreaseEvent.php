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

    public function __serialize(): array
    {
        return [
            0 => $this->pid,
            1 => $this->requests,
        ];
    }

    public function __unserialize(array $data): void
    {
        $this->pid = $data[0];
        $this->requests = $data[1];
    }
}
