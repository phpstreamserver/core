<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\ReloadStrategy;

/**
 * Reload worker after $ttl working time
 */
final class TTLReloadStrategy implements TimerReloadStrategy
{
    /**
     * @param int $ttl TTL in seconds
     */
    public function __construct(private readonly int $ttl)
    {
    }

    public function getInterval(): int
    {
        return $this->ttl;
    }

    public function shouldReload(mixed $eventObject = null): bool
    {
        return true;
    }
}
