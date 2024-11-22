<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Plugin\Supervisor\Internal;

/**
 * @internal
 */
final class ProcessStatus
{
    public int $pid;
    public int $time;
    public bool $detached = false;
    public bool $blocked = false;
    public bool $reloadable = true;

    public function __construct(int $pid, bool $reloadable)
    {
        $this->pid = $pid;
        $this->reloadable = $reloadable;
        $this->time = \hrtime(true);
    }
}
