<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Internal\FFIBindings;

/**
 * @internal
 */
final class OpenBSDProcessMemory
{
    private const CTL_KERN = 1;
    private const KERN_PROC = 66;
    private const KERN_PROC_PID = 1;

    private const CDEF = <<<'CDEF'
        typedef unsigned long size_t;

        struct kinfo_proc {
            unsigned char _reserved[384];
            int p_vm_rssize;
        };

        int sysctl(const int *name, unsigned int namelen, void *oldp, size_t *oldlenp, void *newp, size_t newlen);
        int getpagesize(void);
    CDEF;

    private static \FFI $ffi;
    private static int $pageSize = 0;

    private function __construct()
    {
    }

    private static function ffi(): \FFI
    {
        /** @psalm-suppress RedundantPropertyInitializationCheck */
        return self::$ffi ??= \FFI::cdef(self::CDEF);
    }

    public static function get(int $pid): int
    {
        if (\PHP_OS !== 'OpenBSD') {
            throw new \RuntimeException(\sprintf('%s is only supported on OpenBSD', self::class));
        }

        if ($pid <= 0) {
            throw new \InvalidArgumentException(\sprintf('Invalid process PID: %d', $pid));
        }

        $ffi = self::ffi();
        $processInfo = $ffi->new('struct kinfo_proc');
        $processInfoSize = \FFI::sizeof($processInfo);
        $size = $ffi->new('size_t');
        $size->cdata = $processInfoSize;

        $mib = $ffi->new('int[6]');
        $mib[0] = self::CTL_KERN;
        $mib[1] = self::KERN_PROC;
        $mib[2] = self::KERN_PROC_PID;
        $mib[3] = $pid;
        $mib[4] = $processInfoSize;
        $mib[5] = 1;

        $result = $ffi->sysctl($mib, 6, \FFI::addr($processInfo), \FFI::addr($size), null, 0);
        if ($result !== 0 || $size->cdata !== $processInfoSize) {
            return 0;
        }

        if (self::$pageSize === 0) {
            self::$pageSize = $ffi->getpagesize();
        }

        return (int) $processInfo->p_vm_rssize * self::$pageSize;
    }
}
