<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Internal;

use PHPStreamServer\Core\Internal\FFIBindings\FreeBSDParentDeathSignal;
use PHPStreamServer\Core\Internal\FFIBindings\LinuxParentDeathSignal;

/**
 * @internal
 */
final class ParentDeathSignal
{
    private function __construct()
    {
    }

    public static function set(int $signal, int $masterPid): void
    {
        try {
            if (\PHP_OS_FAMILY === 'Linux') {
                LinuxParentDeathSignal::set($signal);
            } elseif (\PHP_OS_FAMILY === 'BSD' && \PHP_OS === 'FreeBSD') {
                FreeBSDParentDeathSignal::set($signal);
            }
        } finally {
            if ($signal !== 0 && \posix_getppid() !== $masterPid) {
                \posix_kill(\posix_getpid(), $signal);
            }
        }
    }
}
