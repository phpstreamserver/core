<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Internal\ProcessMemory;

/**
 * @internal
 */
final class SolarisProcessMemory
{
    private const PSINFO_PREFIX_SIZE = 64;
    private const RSS_OFFSET = 56;

    private function __construct()
    {
    }

    public static function get(int $pid): int
    {
        if (\PHP_OS_FAMILY !== 'Solaris') {
            throw new \RuntimeException(\sprintf('%s is only supported on Solaris', self::class));
        }

        if ($pid <= 0) {
            throw new \InvalidArgumentException(\sprintf('Invalid process PID: %d', $pid));
        }

        if (!\is_file("/proc/$pid/psinfo")) {
            return 0;
        }

        $psinfo = @\file_get_contents(filename: "/proc/$pid/psinfo", length: self::PSINFO_PREFIX_SIZE);
        if ($psinfo === false || \strlen($psinfo) !== self::PSINFO_PREFIX_SIZE) {
            return 0;
        }

        $rss = \unpack('Qrss', $psinfo, self::RSS_OFFSET);

        return (int) ($rss['rss'] ?? 0) * 1024;
    }
}
