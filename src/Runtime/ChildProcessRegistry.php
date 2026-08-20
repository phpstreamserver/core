<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Runtime;

final class ChildProcessRegistry
{
    /**
     * @var array<int, true>
     */
    private array $registry = [];

    public function __construct()
    {
    }

    public function register(int $pid): void
    {
        $this->registry[$pid] = true;
    }

    public function unregister(int $pid): void
    {
        unset($this->registry[$pid]);
    }

    public function contains(int $pid): bool
    {
        return isset($this->registry[$pid]);
    }
}
