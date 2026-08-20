<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Internal\FFIBindings;

/**
 * @internal
 */
final class NetBSDPeerCredentials
{
    private const SOL_LOCAL = 0;
    private const LOCAL_PEEREID = 3;

    private const CDEF = <<<'CDEF'
        typedef int pid_t;
        typedef unsigned int uid_t;
        typedef unsigned int gid_t;
        typedef unsigned int socklen_t;

        struct unpcbid {
            pid_t unp_pid;
            uid_t unp_euid;
            gid_t unp_egid;
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
        if (\PHP_OS !== 'NetBSD') {
            throw new \RuntimeException(\sprintf('%s is only supported on NetBSD', self::class));
        }

        $ffi = self::ffi();
        $unpcbid = $ffi->new('struct unpcbid');
        $len = $ffi->new('socklen_t');
        $len->cdata = \FFI::sizeof($unpcbid);

        if ($ffi->getsockopt($fd, self::SOL_LOCAL, self::LOCAL_PEEREID, \FFI::addr($unpcbid), \FFI::addr($len)) !== 0) {
            $errno = (int) $ffi->__errno()[0];
            throw new \RuntimeException(\sprintf('Unable to get socket peer credentials: %s', \posix_strerror($errno)));
        }

        if ($len->cdata !== \FFI::sizeof($unpcbid)) {
            throw new \RuntimeException(\sprintf('Unexpected socket credentials size: expected %d bytes, got %d', \FFI::sizeof($unpcbid), $len->cdata));
        }

        $pid = (int) $unpcbid->unp_pid;
        if ($pid <= 0) {
            throw new \RuntimeException(\sprintf('Invalid socket peer PID: %d', $pid));
        }

        $uid = (int) $unpcbid->unp_euid;
        $gid = (int) $unpcbid->unp_egid;

        return [$pid, $uid, $gid];
    }
}
