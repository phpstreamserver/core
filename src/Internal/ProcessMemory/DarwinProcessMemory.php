<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Internal\ProcessMemory;

/**
 * @internal
 */
final class DarwinProcessMemory
{
    private const PROC_PIDTASKINFO = 4;
    private const LIBPROC = '/usr/lib/libproc.dylib';

    private const CDEF = <<<'CDEF'
        typedef unsigned long long uint64_t;

        struct proc_taskinfo {
            uint64_t pti_virtual_size;
            uint64_t pti_resident_size;
            unsigned char reserved[80];
        };

        int proc_pidinfo(int pid, int flavor, uint64_t arg, void *buffer, int buffersize);
    CDEF;

    private static \FFI $ffi;

    private function __construct()
    {
    }

    private static function ffi(): \FFI
    {
        /** @psalm-suppress RedundantPropertyInitializationCheck */
        return self::$ffi ??= \FFI::cdef(self::CDEF, self::LIBPROC);
    }

    public static function get(int $pid): int
    {
        if (\PHP_OS_FAMILY !== 'Darwin') {
            throw new \RuntimeException(\sprintf('%s is only supported on Darwin (macOS)', self::class));
        }

        if ($pid <= 0) {
            throw new \InvalidArgumentException(\sprintf('Invalid process PID: %d', $pid));
        }

        $ffi = self::ffi();
        $taskInfo = $ffi->new('struct proc_taskinfo');
        $result = $ffi->proc_pidinfo($pid, self::PROC_PIDTASKINFO, 0, \FFI::addr($taskInfo), \FFI::sizeof($taskInfo));

        if ($result <= 0) {
            return 0;
        }

        return (int) $taskInfo->pti_resident_size;
    }
}
