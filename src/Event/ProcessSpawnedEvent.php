<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Event;

use PHPStreamServer\Core\MessageBus\AuthorizedSources;
use PHPStreamServer\Core\MessageBus\MessageInterface;
use PHPStreamServer\Core\MessageBus\MessageSource;

/**
 * Process spawned
 * @implements MessageInterface<null>
 */
#[AuthorizedSources(MessageSource::CHILD)]
final readonly class ProcessSpawnedEvent implements MessageInterface
{
    public function __construct(
        public int $workerId,
        public int $pid,
        public string $user,
        public string $name,
        public bool $reloadable,
        public \DateTimeImmutable $startedAt,
    ) {
    }
}
