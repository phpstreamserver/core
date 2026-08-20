<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Internal\FFIBindings;

/**
 * @internal
 */
final class OpenBSDPeerCredentials
{
    private const SOL_SOCKET = 0xffff;
    private const SO_PEERCRED = 0x1022;

    private const CDEF = <<<'CDEF'
        typedef int pid_t;
        typedef unsigned int uid_t;
        typedef unsigned int gid_t;
        typedef unsigned int socklen_t;

        struct sockpeercred {
            uid_t uid;
            gid_t gid;
            pid_t pid;
        };

        int getsockopt(int sockfd, int level, int optname, void *optval, socklen_t *optlen);
        int *__errno(void);
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
        if (\PHP_OS !== 'OpenBSD') {
            throw new \RuntimeException(\sprintf('%s is only supported on OpenBSD', self::class));
        }

        $ffi = self::ffi();
        $sockpeercred = $ffi->new('struct sockpeercred');
        $len = $ffi->new('socklen_t');
        $len->cdata = \FFI::sizeof($sockpeercred);

        if ($ffi->getsockopt($fd, self::SOL_SOCKET, self::SO_PEERCRED, \FFI::addr($sockpeercred), \FFI::addr($len)) !== 0) {
            $errno = (int) $ffi->__errno()[0];
            throw new \RuntimeException(\sprintf('Unable to get socket peer credentials: %s', \posix_strerror($errno)));
        }

        if ($len->cdata !== \FFI::sizeof($sockpeercred)) {
            throw new \RuntimeException(\sprintf('Unexpected socket credentials size: expected %d bytes, got %d', \FFI::sizeof($sockpeercred), $len->cdata));
        }

        $pid = (int) $sockpeercred->pid;
        if ($pid <= 0) {
            throw new \RuntimeException(\sprintf('Invalid socket peer PID: %d', $pid));
        }

        $uid = (int) $sockpeercred->uid;
        $gid = (int) $sockpeercred->gid;

        return [$pid, $uid, $gid];
    }
}
