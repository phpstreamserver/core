<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Internal\CloseOnExec;

/**
 * @internal
 */
final class LinuxCloseOnExec
{
    private const CLOSE_RANGE_CLOEXEC = 1 << 2;
    private const F_GETFD = 1;
    private const F_SETFD = 2;
    private const FD_CLOEXEC = 1;
    private const EBADF = 9;

    private const CLOSE_RANGE_CDEF = <<<'CDEF'
        int close_range(unsigned int first, unsigned int last, int flags);
    CDEF;

    private const FCNTL_CDEF = <<<'CDEF'
        int fcntl(int fd, int op, ...);
        int *__errno_location(void);
    CDEF;

    public static function set(): void
    {
        if (\PHP_OS_FAMILY !== 'Linux') {
            throw new \RuntimeException(\sprintf('%s is only supported on Linux', self::class));
        }

        try {
            $ffi = \FFI::cdef(self::CLOSE_RANGE_CDEF);
            if ($ffi->close_range(3, -1, self::CLOSE_RANGE_CLOEXEC) === 0) {
                return;
            }
        } catch (\FFI\Exception) {
            // close_range() is unavailable before glibc 2.34
        }

        self::fcntlFallback();
    }

    private static function fcntlFallback(): void
    {
        $descriptors = @\scandir('/proc/self/fd');
        if ($descriptors === false) {
            throw new \RuntimeException('Unable to enumerate open file descriptors');
        }

        $ffi = \FFI::cdef(self::FCNTL_CDEF);
        foreach ($descriptors as $descriptor) {
            if (!\ctype_digit($descriptor) || ($fd = (int) $descriptor) < 3) {
                continue;
            }

            $flags = $ffi->fcntl($fd, self::F_GETFD);

            if ($flags === -1) {
                if (self::EBADF !== $errno = (int) $ffi->__errno_location()[0]) {
                    throw new \RuntimeException(\sprintf('Unable to mark file descriptor %d close-on-exec: %s', $fd, \posix_strerror($errno)));
                }
                continue;
            }

            if (($flags & self::FD_CLOEXEC) !== 0) {
                continue;
            }

            if ($ffi->fcntl($fd, self::F_SETFD, $flags | self::FD_CLOEXEC) === -1) {
                if (self::EBADF !== $errno = (int) $ffi->__errno_location()[0]) {
                    throw new \RuntimeException(\sprintf('Unable to mark file descriptor %d close-on-exec: %s', $fd, \posix_strerror($errno)));
                }
            }
        }
    }
}
