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
                if (\pcntl_wifexited($status)) {
                    $terminationSignal = null;
                    $exitCode = \pcntl_wexitstatus($status) ?: 0;
                } elseif (\pcntl_wifsignaled($status)) {
                    $terminationSignal = \pcntl_wtermsig($status) ?: 0;
                    $exitCode = 128 + $terminationSignal;
                } else {
                    continue;
                }

                foreach (self::$callbacks as $callback) {
                    try {
                        $callback($pid, $exitCode, $terminationSignal);
                    } catch (\Throwable $e) {
                        EventLoop::queue(static function () use ($pid, $e): void {
                            \trigger_error(
                                \sprintf('SIGCHLD callback failed for child PID %d: %s: %s in %s:%d', $pid, $e::class, $e->getMessage(), $e->getFile(), $e->getLine()),
                                \E_USER_WARNING,
                            );
                        });
                    }
                }
            }
        });
    }

    /**
     * @param \Closure(int, int, int|null): void $closure
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

        EventLoop::cancel(self::$signalCallbackId);
        self::$isRegistered = false;
        self::$signalCallbackId = '';
        self::$callbacks = [];
    }
}
