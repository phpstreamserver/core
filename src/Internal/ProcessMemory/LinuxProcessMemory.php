<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Internal\ProcessMemory;

/**
 * @internal
 */
final class LinuxProcessMemory
{
    private const CDEF = <<<'CDEF'
        int open(const char *pathname, int flags);
        long read(int fd, void *buf, size_t count);
        int close(int fd);
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
        if (\PHP_OS_FAMILY !== 'Linux') {
            throw new \RuntimeException(\sprintf('%s is only supported on Linux', self::class));
        }

        if ($pid <= 0) {
            throw new \InvalidArgumentException(\sprintf('Invalid process PID: %d', $pid));
        }

        $ffi = self::ffi();
        $fd = $ffi->open("/proc/$pid/statm", 0);
        if ($fd < 0) {
            return 0;
        }

        $buf = $ffi->new('char[64]');
        $bytes = (int) $ffi->read($fd, \FFI::addr($buf), 63);
        $ffi->close($fd);

        if ($bytes <= 0) {
            return 0;
        }

        if (self::$pageSize === 0) {
            self::$pageSize = $ffi->getpagesize();
        }

        $statm = \FFI::string($buf, $bytes);
        $parts = \explode(' ', $statm, 3);

        return (int) ($parts[1] ?? 0) * self::$pageSize;
    }
}
