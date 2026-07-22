<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\ReloadStrategy;

interface TimerReloadStrategy extends ReloadStrategy
{
    /**
     * The strategy is triggered repeatedly at the configured interval.
     *
     * @return int timer interval in seconds
     */
    public function getInterval(): int;
}
