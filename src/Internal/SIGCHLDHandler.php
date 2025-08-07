<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Internal;

use Revolt\EventLoop;

/**
 * @internal
 */
final class SIGCHLDHandler
{
    private static bool $isRegistered = false;
    private static string $signalCallbackId = '';
    private static array $callbacks = [];

    private function __construct()
    {
    }

    private static function register(): void
    {
        self::$isRegistered = true;
        self::$signalCallbackId = EventLoop::onSignal(SIGCHLD, static function (): void {
            while (($pid = \pcntl_wait($status, WNOHANG)) > 0) {
                $exitCode = \pcntl_wexitstatus($status) ?: 0;
                foreach (self::$callbacks as $callback) {
                    $callback($pid, $exitCode);
                }
            }
        });
    }

    /**
     * @param \Closure(int, int): void $closure
     */
    public static function onChildProcessExit(\Closure $closure): void
    {
        if (!self::$isRegistered) {
            self::register();
        }

        self::$callbacks[] = $closure;
    }

    public static function unregister(): void
    {
        if (!self::$isRegistered) {
            return;
        }

        EventLoop::disable(self::$signalCallbackId);
        self::$isRegistered = false;
        self::$signalCallbackId = '';
        self::$callbacks = [];
    }
}
