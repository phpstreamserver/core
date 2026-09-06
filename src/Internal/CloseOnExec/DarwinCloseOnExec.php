<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Internal\CloseOnExec;

/**
 * @internal
 */
final class DarwinCloseOnExec
{
    private const F_GETFD = 1;
    private const F_SETFD = 2;
    private const FD_CLOEXEC = 1;
    private const EBADF = 9;

    private const FCNTL_CDEF = <<<'CDEF'
        int fcntl(int fd, int op, ...);
        int *__error(void);
    CDEF;

    private function __construct()
    {
    }

    public static function set(): void
    {
        if (\PHP_OS_FAMILY !== 'Darwin') {
            throw new \RuntimeException(\sprintf('%s is only supported on Darwin (macOS)', self::class));
        }

        $ffi = \FFI::cdef(self::FCNTL_CDEF);

        $descriptors = @\scandir('/dev/fd');
        if ($descriptors === false) {
            throw new \RuntimeException('Unable to enumerate open file descriptors');
        }

        foreach ($descriptors as $descriptor) {
            if (!\ctype_digit($descriptor) || ($fd = (int) $descriptor) < 3) {
                continue;
            }

            $flags = $ffi->fcntl($fd, self::F_GETFD);

            if ($flags === -1) {
                if (self::EBADF !== $errno = (int) $ffi->__error()[0]) {
                    throw new \RuntimeException(\sprintf('Unable to mark file descriptor %d close-on-exec: %s', $fd, \posix_strerror($errno)));
                }
                continue;
            }

            if (($flags & self::FD_CLOEXEC) !== 0) {
                continue;
            }

            if ($ffi->fcntl($fd, self::F_SETFD, $flags | self::FD_CLOEXEC) === -1) {
                if (self::EBADF !== $errno = (int) $ffi->__error()[0]) {
                    throw new \RuntimeException(\sprintf('Unable to mark file descriptor %d close-on-exec: %s', $fd, \posix_strerror($errno)));
                }
            }
        }
    }
}
