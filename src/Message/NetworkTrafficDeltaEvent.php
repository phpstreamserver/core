<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Message;

use PHPStreamServer\Core\MessageBus\MessageInterface;
use PHPStreamServer\Core\Plugin\System\Connections\Connection;

/**
 * @implements MessageInterface<null>
 */
final readonly class NetworkTrafficDeltaEvent implements MessageInterface
{
    /**
     * @param array<int, Connection> $createdConnections
     * @param array<int> $closedConnectionIds
     * @param array<int, int> $rxTrafficByConnection
     * @param array<int, int> $txTrafficByConnection
     */
    public function __construct(
        public int $pid,
        public array $createdConnections,
        public array $closedConnectionIds,
        public array $rxTrafficByConnection,
        public array $txTrafficByConnection,
        public int $requests,
    ) {
    }

    public function __serialize(): array
    {
        return [
            0 => $this->pid,
            1 => $this->createdConnections,
            2 => $this->closedConnectionIds,
            3 => $this->rxTrafficByConnection,
            4 => $this->txTrafficByConnection,
            5 => $this->requests,
        ];
    }

    public function __unserialize(array $data): void
    {
        $this->pid = $data[0];
        $this->createdConnections = $data[1];
        $this->closedConnectionIds = $data[2];
        $this->rxTrafficByConnection = $data[3];
        $this->txTrafficByConnection = $data[4];
        $this->requests = $data[5];
    }
}
