<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Internal\FFIBindings;

/**
 * @internal
 */
final class LinuxPeerCredentials
{
    private const CDEF = <<<'CDEF'
        typedef int pid_t;
        typedef unsigned int uid_t;
        typedef unsigned int gid_t;
        typedef unsigned int socklen_t;

        struct ucred {
            pid_t pid;
            uid_t uid;
            gid_t gid;
        };

        int getsockopt(int sockfd, int level, int optname, void *optval, socklen_t *optlen);
        int *__errno_location(void);
    CDEF;

    private static \FFI $ffi;

    /**
     * @psalm-suppress RedundantPropertyInitializationCheck
     */
    private static function ffi(): \FFI
    {
        return self::$ffi ??= \FFI::cdef(self::CDEF);
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    public static function get(int $fd): array
    {
        if (\PHP_OS_FAMILY !== 'Linux') {
            throw new \RuntimeException(\sprintf('%s is only supported on Linux', self::class));
        }

        $ffi = self::ffi();
        $ucred = $ffi->new('struct ucred');
        $len = $ffi->new('socklen_t');
        $len->cdata = \FFI::sizeof($ucred);

        if ($ffi->getsockopt($fd, self::solSocket(), self::soPeercred(), \FFI::addr($ucred), \FFI::addr($len)) !== 0) {
            $errno = (int) $ffi->__errno_location()[0];
            throw new \RuntimeException(\sprintf('Unable to get socket peer credentials: %s', \posix_strerror($errno)));
        }

        if ($len->cdata !== \FFI::sizeof($ucred)) {
            throw new \RuntimeException(\sprintf('Unexpected socket credentials size: expected %d bytes, got %d', \FFI::sizeof($ucred), $len->cdata));
        }

        $pid = (int) $ucred->pid;
        if ($pid <= 0) {
            throw new \RuntimeException(\sprintf('Invalid socket peer PID: %d', $pid));
        }

        $uid = (int) $ucred->uid;
        $gid = (int) $ucred->gid;

        return [$pid, $uid, $gid];
    }

    private static function solSocket(): int
    {
        static $opt;
        if ($opt !== null) {
            return $opt;
        }

        if (\defined('SOL_SOCKET')) {
            return $opt = \SOL_SOCKET;
        }

        $arch = \php_uname('m');

        return $opt = match (true) {
            \str_starts_with($arch, 'mips'),
            \str_starts_with($arch, 'sparc'),
            \str_starts_with($arch, 'alpha'),
            \str_starts_with($arch, 'parisc'),
            \str_starts_with($arch, 'hppa') => 65535, // 0xffff
            default => 1,
        };
    }

    private static function soPeercred(): int
    {
        static $opt;
        if ($opt !== null) {
            return $opt;
        }

        $arch = \php_uname('m');

        return $opt = match (true) {
            \str_starts_with($arch, 'ppc'),
            \str_starts_with($arch, 'powerpc') => 21,
            \str_starts_with($arch, 'mips'),
            \str_starts_with($arch, 'alpha') => 18,
            \str_starts_with($arch, 'sparc') => 64, // 0x0040
            \str_starts_with($arch, 'parisc'),
            \str_starts_with($arch, 'hppa') => 16401, // 0x4011
            default => 17,
        };
    }
}
