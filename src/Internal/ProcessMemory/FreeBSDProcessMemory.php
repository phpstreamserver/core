<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Internal\ProcessMemory;

/**
 * @internal
 */
final class FreeBSDProcessMemory
{
    private const LIBUTIL = 'libutil.so';

    private const CDEF = <<<'CDEF'
        struct kinfo_proc {
            int ki_structsize;
            int ki_layout;
            void *_reserved_pointers[8];
            unsigned char _reserved[184];
            unsigned long ki_size;
            long ki_rssize;
        };

        struct kinfo_proc *kinfo_getproc(int pid);
        int getpagesize(void);
        void free(void *pointer);
    CDEF;

    private static \FFI $ffi;
    private static int $pageSize = 0;

    private function __construct()
    {
    }

    private static function ffi(): \FFI
    {
        /** @psalm-suppress RedundantPropertyInitializationCheck */
        return self::$ffi ??= \FFI::cdef(self::CDEF, self::LIBUTIL);
    }

    public static function get(int $pid): int
    {
        if (\PHP_OS !== 'FreeBSD') {
            throw new \RuntimeException(\sprintf('%s is only supported on FreeBSD', self::class));
        }

        if ($pid <= 0) {
            throw new \InvalidArgumentException(\sprintf('Invalid process PID: %d', $pid));
        }

        $ffi = self::ffi();
        $processInfo = $ffi->kinfo_getproc($pid);

        if (\FFI::isNull($processInfo)) {
            return 0;
        }

        try {
            if (self::$pageSize === 0) {
                self::$pageSize = $ffi->getpagesize();
            }

            return (int) $processInfo->ki_rssize * self::$pageSize;
        } finally {
            $ffi->free($processInfo);
        }
    }
}
