<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Message;

use PHPStreamServer\Core\MessageBus\MessageInterface;
use PHPStreamServer\Core\Plugin\System\Connections\Connection;

/**
 * @implements MessageInterface<null>
 */
final readonly class ConnectionCreatedEvent implements MessageInterface
{
    public function __construct(
        public int $pid,
        public int $connectionId,
        public Connection $connection,
    ) {
    }

    public function __serialize(): array
    {
        return [
            0 => $this->pid,
            1 => $this->connectionId,
            2 => $this->connection,
        ];
    }

    public function __unserialize(array $data): void
    {
        $this->pid = $data[0];
        $this->connectionId = $data[1];
        $this->connection = $data[2];
    }
}
