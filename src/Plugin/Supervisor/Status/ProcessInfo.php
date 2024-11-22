<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Plugin\Supervisor\Status;

final class ProcessInfo
{
    public function __construct(
        public int $workerId,
        public int $pid,
        public string $user,
        public string $name,
        public \DateTimeImmutable $startedAt,
        public int $memory = 0,
        public bool $detached = false,
        public bool $blocked = false,
    ) {
    }
}
