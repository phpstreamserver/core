<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Plugin\System\Connections;

final class Connection
{
    public function __construct(
        public readonly int $pid,
        public readonly \DateTimeImmutable $connectedAt,
        public readonly string $localIp,
        public readonly string $localPort,
        public readonly string $remoteIp,
        public readonly string $remotePort,
        public int $rx = 0,
        public int $tx = 0,
    ) {
    }
}
