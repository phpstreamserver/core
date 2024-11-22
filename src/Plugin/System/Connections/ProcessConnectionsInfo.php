<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Plugin\System\Connections;

final class ProcessConnectionsInfo
{
    /**
     * @param array<int, Connection> $connections
     */
    public function __construct(
        public readonly int $pid,
        public int $requests = 0,
        public int $rx = 0,
        public int $tx = 0,
        public array $connections = [],
    ) {
    }
}
