<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Internal\CloseOnExec;

/**
 * @internal
 */
final class OpenBSDCloseOnExec
{
    private const F_GETFD = 1;
    private const F_SETFD = 2;
    private const FD_CLOEXEC = 1;
    private const EBADF = 9;

    private const CDEF = <<<'CDEF'
        int fcntl(int fd, int op, ...);
        int getdtablecount(void);
        int getdtablesize(void);
        int *__errno(void);
    CDEF;

    public static function set(): void
    {
        if (\PHP_OS !== 'OpenBSD') {
            throw new \RuntimeException(\sprintf('%s is only supported on OpenBSD', self::class));
        }

        $ffi = \FFI::cdef(self::CDEF);
        $openDescriptorCount = $ffi->getdtablecount();
        $descriptorLimit = $ffi->getdtablesize();

        for ($fd = 0; $openDescriptorCount > 0 && $fd < $descriptorLimit; $fd++) {
            $flags = $ffi->fcntl($fd, self::F_GETFD);

            if ($flags === -1) {
                if (self::EBADF !== $errno = (int) $ffi->__errno()[0]) {
                    throw new \RuntimeException(\sprintf('Unable to mark file descriptor %d close-on-exec: %s', $fd, \posix_strerror($errno)));
                }
                continue;
            }

            $openDescriptorCount--;

            if ($fd < 3 || ($flags & self::FD_CLOEXEC) !== 0) {
                continue;
            }

            if ($ffi->fcntl($fd, self::F_SETFD, $flags | self::FD_CLOEXEC) === -1) {
                if (self::EBADF !== $errno = (int) $ffi->__errno()[0]) {
                    throw new \RuntimeException(\sprintf('Unable to mark file descriptor %d close-on-exec: %s', $fd, \posix_strerror($errno)));
                }
            }
        }
    }
}
