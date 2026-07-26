<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Plugin\System;

final readonly class ServerStatus
{
    public function __construct(
        public string $eventLoop,
        public string $startFile,
        public \DateTimeImmutable $startedAt,
    ) {
    }
}
