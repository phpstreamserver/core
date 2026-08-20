<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Internal\FFIBindings;

/**
 * @internal
 */
final class SolarisPeerCredentials
{
    private const CDEF = <<<'CDEF'
        typedef int pid_t;
        typedef unsigned int uid_t;
        typedef unsigned int gid_t;
        typedef struct ucred ucred_t;

        int getpeerucred(int fd, ucred_t **ucred);
        pid_t ucred_getpid(const ucred_t *uc);
        uid_t ucred_geteuid(const ucred_t *uc);
        gid_t ucred_getegid(const ucred_t *uc);
        void ucred_free(ucred_t *uc);
        int *___errno(void);
    CDEF;

    private static \FFI $ffi;

    private static function ffi(): \FFI
    {
        /** @psalm-suppress RedundantPropertyInitializationCheck */
        return self::$ffi ??= \FFI::cdef(self::CDEF);
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    public static function get(int $fd): array
    {
        if (\PHP_OS_FAMILY !== 'Solaris') {
            throw new \RuntimeException(\sprintf('%s is only supported on Solaris/illumos', self::class));
        }

        $ffi = self::ffi();
        $ucredPtr = $ffi->new('ucred_t*');

        if ($ffi->getpeerucred($fd, \FFI::addr($ucredPtr)) !== 0) {
            $errno = (int) $ffi->___errno()[0];
            throw new \RuntimeException(\sprintf('Unable to get socket peer credentials: %s', \posix_strerror($errno)));
        }

        try {
            $pid = (int) $ffi->ucred_getpid($ucredPtr);
            if ($pid <= 0) {
                throw new \RuntimeException(\sprintf('Invalid socket peer PID: %d', $pid));
            }

            $uid = (int) $ffi->ucred_geteuid($ucredPtr);
            if ($uid < 0 || $uid === 0xFFFFFFFF) {
                throw new \RuntimeException(\sprintf('Invalid socket peer UID: %d', $uid));
            }

            $gid = (int) $ffi->ucred_getegid($ucredPtr);
            if ($gid < 0 || $gid === 0xFFFFFFFF) {
                throw new \RuntimeException(\sprintf('Invalid socket peer GID: %d', $gid));
            }

            return [$pid, $uid, $gid];
        } finally {
            $ffi->ucred_free($ucredPtr);
        }
    }
}
