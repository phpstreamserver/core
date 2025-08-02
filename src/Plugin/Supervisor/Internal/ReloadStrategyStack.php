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

    private readonly \Closure $reloadCallback;

    /**
     * @param array<ReloadStrategy> $reloadStrategies
     */
    public function __construct(\Closure $reloadCallback, array $reloadStrategies = [])
    {
        $this->reloadCallback = static function () use ($reloadCallback): void {
            EventLoop::defer(static function () use ($reloadCallback): void {
                $reloadCallback();
            });
        };

        $this->addReloadStrategy(...$reloadStrategies);
    }

    public function addReloadStrategy(ReloadStrategy ...$reloadStrategies): void
    {
        foreach ($reloadStrategies as $reloadStrategy) {
            if ($reloadStrategy instanceof TimerReloadStrategy) {
                $reloadCallback = $this->reloadCallback;
                EventLoop::repeat($reloadStrategy->getInterval(), static function () use ($reloadStrategy, $reloadCallback): void {
                    if ($reloadStrategy->shouldReload()) {
                        $reloadCallback();
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
                ($this->reloadCallback)();
                break;
            }
        }
    }
}
