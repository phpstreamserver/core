<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Internal\CloseOnExec;

/**
 * @internal
 */
final class FreeBSDCloseOnExec
{
    private const CLOSE_RANGE_CLOEXEC = 1 << 2;

    private const CLOSE_RANGE_CDEF = <<<'CDEF'
        int close_range(unsigned int first, unsigned int last, int flags);
        int *__error(void);
    CDEF;

    private function __construct()
    {
    }

    public static function set(): void
    {
        if (\PHP_OS !== 'FreeBSD') {
            throw new \RuntimeException(\sprintf('%s is only supported on FreeBSD', self::class));
        }

        $ffi = \FFI::cdef(self::CLOSE_RANGE_CDEF);
        if ($ffi->close_range(3, -1, self::CLOSE_RANGE_CLOEXEC) !== 0) {
            $errno = (int) $ffi->__error()[0];
            throw new \RuntimeException(\sprintf('Unable to mark file descriptors close-on-exec: %s', \posix_strerror($errno)));
        }
    }
}
