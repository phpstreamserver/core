<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Plugin\Supervisor\Internal;

use PHPStreamServer\Core\ReloadStrategy\ReloadStrategy;
use PHPStreamServer\Core\ReloadStrategy\TimerReloadStrategy;
use Revolt\EventLoop;

/**
 * @internal
 */
final class ReloadStrategyStack
{
    /** @var list<ReloadStrategy> */
    private array $reloadStrategies = [];

    private bool $reloadState = false;

    /**
     * @param array<ReloadStrategy> $reloadStrategies
     */
    public function __construct(private readonly \Closure $reloadCallback, array $reloadStrategies = [])
    {
        $this->addReloadStrategy(...$reloadStrategies);
    }

    public function addReloadStrategy(ReloadStrategy ...$reloadStrategies): void
    {
        foreach ($reloadStrategies as $reloadStrategy) {
            if ($reloadStrategy instanceof TimerReloadStrategy) {
                EventLoop::repeat($reloadStrategy->getInterval(), function () use ($reloadStrategy): void {
                    if ($reloadStrategy->shouldReload()) {
                        $this->reload();
                    }
                });
            } else {
                $this->reloadStrategies[] = $reloadStrategy;
            }
        }
    }

    /**
     * @param mixed $event any value that checked by reload strategies. Could be exception, request etc.
     */
    public function emitEvent(mixed $event): void
    {
        if ($this->reloadState) {
            return;
        }

        foreach ($this->reloadStrategies as $reloadStrategy) {
            if ($reloadStrategy->shouldReload($event)) {
                $this->reloadState = true;
                $this->reload();
                break;
            }
        }
    }

    private function reload(): void
    {
        EventLoop::defer(function (): void {
            ($this->reloadCallback)();
        });
    }
}
