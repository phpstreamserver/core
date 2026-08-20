<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Internal\FFIBindings;

/**
 * @internal
 */
final class FreeBSDPeerCredentials
{
    private const SOL_LOCAL = 0;
    private const LOCAL_PEERCRED = 1;
    private const XUCRED_VERSION = 0;

    private const CDEF = <<<'CDEF'
        typedef int pid_t;
        typedef unsigned int uid_t;
        typedef unsigned int gid_t;
        typedef unsigned int socklen_t;

        struct xucred {
            unsigned int cr_version;
            uid_t cr_uid;
            short cr_ngroups;
            gid_t cr_groups[16];
            union {
                void *_cr_unused1;
                pid_t cr_pid;
            };
        };

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
        if (\PHP_OS !== 'FreeBSD') {
            throw new \RuntimeException(\sprintf('%s is only supported on FreeBSD', self::class));
        }

        $ffi = self::ffi();
        $xucred = $ffi->new('struct xucred');
        $len = $ffi->new('socklen_t');
        $len->cdata = \FFI::sizeof($xucred);

        if ($ffi->getsockopt($fd, self::SOL_LOCAL, self::LOCAL_PEERCRED, \FFI::addr($xucred), \FFI::addr($len)) !== 0) {
            $errno = (int) $ffi->__error()[0];
            throw new \RuntimeException(\sprintf('Unable to get socket peer credentials: %s', \posix_strerror($errno)));
        }

        if ($len->cdata !== \FFI::sizeof($xucred)) {
            throw new \RuntimeException(\sprintf('Unexpected socket credentials size: expected %d bytes, got %d', \FFI::sizeof($xucred), $len->cdata));
        }

        $version = (int) $xucred->cr_version;
        if ($version !== self::XUCRED_VERSION) {
            throw new \RuntimeException(\sprintf('Unsupported FreeBSD xucred version: %d', $version));
        }

        $ngroups = (int) $xucred->cr_ngroups;
        if ($ngroups < 1 || $ngroups > 16) {
            throw new \RuntimeException(\sprintf('Invalid FreeBSD xucred ngroups: %d', $ngroups));
        }

        $pid = (int) $xucred->cr_pid;
        if ($pid <= 0) {
            throw new \RuntimeException(\sprintf('Invalid socket peer PID: %d', $pid));
        }

        $uid = (int) $xucred->cr_uid;
        $gid = (int) $xucred->cr_groups[0];

        return [$pid, $uid, $gid];
    }
}
