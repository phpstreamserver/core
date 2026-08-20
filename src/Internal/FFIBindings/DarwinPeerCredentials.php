<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Internal\FFIBindings;

/**
 * @internal
 */
final class DarwinPeerCredentials
{
    private const SOL_LOCAL = 0;
    private const LOCAL_PEERPID = 2;

    private const CDEF = <<<'CDEF'
        typedef int pid_t;
        typedef unsigned int uid_t;
        typedef unsigned int gid_t;
        typedef unsigned int socklen_t;

        int getpeereid(int socket, uid_t *euid, gid_t *egid);
        int getsockopt(int sockfd, int level, int optname, void *optval, socklen_t *optlen);
        int *__error(void);
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
        if (\PHP_OS_FAMILY !== 'Darwin') {
            throw new \RuntimeException(\sprintf('%s is only supported on Darwin (macOS)', self::class));
        }

        $ffi = self::ffi();
        $uid = $ffi->new('uid_t');
        $gid = $ffi->new('gid_t');

        if ($ffi->getpeereid($fd, \FFI::addr($uid), \FFI::addr($gid)) !== 0) {
            $errno = (int) $ffi->__error()[0];
            throw new \RuntimeException(\sprintf('Unable to get socket peer credentials: %s', \posix_strerror($errno)));
        }

        $pid = $ffi->new('pid_t');
        $pidLength = $ffi->new('socklen_t');
        $pidLength->cdata = \FFI::sizeof($pid);

        if ($ffi->getsockopt($fd, self::SOL_LOCAL, self::LOCAL_PEERPID, \FFI::addr($pid), \FFI::addr($pidLength)) !== 0) {
            $errno = (int) $ffi->__error()[0];
            throw new \RuntimeException(\sprintf('Unable to get socket peer PID: %s', \posix_strerror($errno)));
        }

        if ($pidLength->cdata !== \FFI::sizeof($pid)) {
            throw new \RuntimeException(\sprintf('Unexpected socket peer PID size: expected %d bytes, got %d', \FFI::sizeof($pid), $pidLength->cdata));
        }

        $pidVal = (int) $pid->cdata;
        if ($pidVal <= 0) {
            throw new \RuntimeException(\sprintf('Invalid socket peer PID: %d', $pidVal));
        }

        $uidVal = (int) $uid->cdata;
        $gidVal = (int) $gid->cdata;

        return [$pidVal, $uidVal, $gidVal];
    }
}
