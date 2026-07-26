<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Plugin\Supervisor;

final class ProcessInfo
{
    public function __construct(
        public int $workerId,
        public int $pid,
        public string $user,
        public string $name,
        public \DateTimeImmutable $startedAt,
        public int $heartbeatTime,
        public int $memory = 0,
        public bool $external = false,
        public bool $blocked = false,
        public bool $reloadable = true,
    ) {
    }
}
