<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Plugin\Supervisor\Status;

final readonly class WorkerInfo
{
    public function __construct(
        public int $id,
        public string $user,
        public string $name,
        public int $count,
        public bool $reloadable,
    ) {
    }
}
