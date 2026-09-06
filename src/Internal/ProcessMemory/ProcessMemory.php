<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Internal\ProcessMemory;

use PHPStreamServer\Core\Internal\FFIBindings\OpenBSDProcessMemory;

/**
 * @internal
 */
final readonly class ProcessMemory
{
    private function __construct()
    {
    }

    public static function get(int $pid): int
    {
        if ($pid <= 0) {
            throw new \InvalidArgumentException(\sprintf('Invalid process PID: %d', $pid));
        }

        return match (\PHP_OS_FAMILY) {
            'Linux' => LinuxProcessMemory::get($pid),
            'Darwin' => DarwinProcessMemory::get($pid),
            'Solaris' => SolarisProcessMemory::get($pid),
            'BSD' => match (\PHP_OS) {
                'FreeBSD' => FreeBSDProcessMemory::get($pid),
                'OpenBSD' => OpenBSDProcessMemory::get($pid),
                'NetBSD' => NetBSDProcessMemory::get($pid),
                default => 0,
            },
            default => 0,
        };
    }
}
