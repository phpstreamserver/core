<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\ReloadStrategy;

/**
 * Reloads the worker when its memory usage exceeds the specified threshold
 */
final class MaxMemoryReloadStrategy implements TimerReloadStrategy
{
    private const TIMER_INTERVAL = 30;

    public function __construct(private readonly int $maxMemory)
    {
    }

    public function getInterval(): int
    {
        return self::TIMER_INTERVAL;
    }

    public function shouldReload(mixed $eventObject = null): bool
    {
        return \max(\memory_get_peak_usage(), \memory_get_usage()) > $this->maxMemory;
    }
}
