<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Plugin\Supervisor;

final class WorkerInfo
{
    public const STATUS_STARTING = 'starting';
    public const STATUS_RUNNING = 'running';
    public const STATUS_STOPPING = 'stopping';

    /**
     * @param self::STATUS_* $status
     */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $user,
        public readonly string $group,
        public string $status,
        public readonly int $processCount,
        public readonly bool $reloadable,
        public readonly string|null $factoryId,
        public readonly \DateTimeImmutable $startedAt,
    ) {
    }
}
