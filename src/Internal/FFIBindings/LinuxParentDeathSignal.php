<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Internal\FFIBindings;

use function PHPStreamServer\Core\strSignal;

/**
 * @internal
 */
final class LinuxParentDeathSignal
{
    private const PR_SET_PDEATHSIG = 1;

    private const CDEF = <<<'CDEF'
        int prctl(int option, ...);
        int *__errno_location(void);
    CDEF;

    public static function set(int $signal): void
    {
        if (\PHP_OS_FAMILY !== 'Linux') {
            throw new \RuntimeException(\sprintf('%s is only supported on Linux', self::class));
        }

        $ffi = \FFI::cdef(self::CDEF);
        if ($ffi->prctl(self::PR_SET_PDEATHSIG, $signal, 0, 0, 0) !== 0) {
            $errno = (int) $ffi->__errno_location()[0];
            throw new \RuntimeException(\sprintf('Unable to set parent-death signal %s (%d): %s', strSignal($signal), $signal, \posix_strerror($errno)));
        }
    }
}
