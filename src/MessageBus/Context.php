<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\MessageBus;

final readonly class Context
{
    public function __construct(
        public MessageSource $source,
        public int $pid,
        public int $uid,
        public int $gid,
        public string $user,
        public string $group,
    ) {
    }
}
