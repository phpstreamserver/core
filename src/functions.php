<?php

declare(strict_types=1);

namespace PHPStreamServer\Core;

use PHPStreamServer\Core\Internal\ProcessIdentity;
use Revolt\EventLoop\DriverFactory;

function getStartFile(): string
{
    static $file;
    if (!isset($file)) {
        /** @var array<array{file: string}> $backtrace */
        $backtrace = \debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        $file = \end($backtrace)['file'];
    }
    return $file;
}

function formatFileSize(int $bytes): string
{
    if ($bytes < 1024) {
        return "$bytes B";
    }
    $bytes = \round($bytes / 1024, 0);
    if ($bytes < 1024) {
        return "$bytes KiB";
    }
    $bytes = \round($bytes / 1024, 1);
    if ($bytes < 1024) {
        return "$bytes MiB";
    }
    $bytes = \round($bytes / 1024, 1);
    if ($bytes < 1024) {
        return "$bytes GiB";
    }
    $bytes = \round($bytes / 1024, 1);
    return "$bytes TiB";
}

function formatDuration(\DateTimeInterface $startedAt): string
{
    $seconds = \max(0, \time() - $startedAt->getTimestamp());
    $days = \intdiv($seconds, 86400);
    $hours = \intdiv($seconds % 86400, 3600);
    $minutes = \intdiv($seconds % 3600, 60);

    return match (true) {
        $seconds < 60 => \sprintf('%ds', $seconds),
        $days > 0 => \sprintf('%dd %dh %dm', $days, $hours, $minutes),
        $hours > 0 => \sprintf('%dh %dm', $hours, $minutes),
        default => \sprintf('%dm', $minutes),
    };
}

function reportErrors(): bool
{
    return (\error_reporting() & \E_ERROR) === \E_ERROR;
}

function isRunning(string $pidFile): bool
{
    $pid = getPid($pidFile);

    if ($pid === 0) {
        return false;
    }

    return \posix_kill($pid, 0) || \posix_get_last_error() === \PCNTL_EPERM;
}

function getPid(string $pidFile): int
{
    return \is_file($pidFile) ? (int) \file_get_contents($pidFile) : 0;
}

function getRunDirectory(): string
{
    static $dir;
    return $dir ??= \posix_access('/run/', POSIX_R_OK | POSIX_W_OK) ? '/run' : \sys_get_temp_dir();
}

function getDefaultPidFile(): string
{
    return \sprintf('%s/%s-%s.pid', getRunDirectory(), Server::SHORTNAME, \hash('xxh32', getStartFile()));
}

function getDefaultSocketFile(): string
{
    return \sprintf('%s/%s-%s.socket', getRunDirectory(), Server::SHORTNAME, \hash('xxh32', getStartFile()));
}

function getAbsoluteBinaryPath(string $binary): string
{
    /** @psalm-suppress ForbiddenCode */
    if (!\str_starts_with($binary, '/') && \is_string($absoluteBinaryPath = \shell_exec("command -v $binary"))) {
        $binary = \trim($absoluteBinaryPath);
    }

    return $binary;
}

function getMemoryUsageByPid(int $pid): int
{
    if (PHP_VERSION_ID >= 80300 && \is_file("/proc/$pid/statm")) {
        $pagesize = \posix_sysconf(POSIX_SC_PAGESIZE);
        $statm = \trim(\file_get_contents("/proc/$pid/statm"));
        $statm = \explode(' ', $statm);
        $vmrss = (int) ($statm[1] ?? 0) * $pagesize;
    } else {
        /** @psalm-suppress ForbiddenCode */
        $out = \shell_exec("ps -o rss= -p $pid 2>/dev/null");
        $vmrss = ((int) \trim((string) $out)) * 1024;
    }

    return $vmrss;
}

function getDriverName(): string
{
    return (new \ReflectionObject((new DriverFactory())->create()))->getShortName();
}

function getCpuCount(): int
{
    if (\PHP_VERSION_ID >= 80300) {
        return \posix_sysconf(\POSIX_SC_NPROCESSORS_ONLN);
    } elseif (\DIRECTORY_SEPARATOR === '/' && \function_exists('shell_exec')) {
        /** @psalm-suppress ForbiddenCode */
        return \strtolower(\PHP_OS) === 'darwin' ? (int) \shell_exec('sysctl -n machdep.cpu.core_count') : (int) \shell_exec('nproc');
    } else {
        return 1;
    }
}

function generateWorkerId(): int
{
    static $nextWorkerId = 1;
    return $nextWorkerId++;
}

function strSignal(int $signal): string
{
    static $signals = null;

    if ($signals === null) {
        $signals = [];
        foreach (\get_defined_constants(true)['pcntl'] ?? [] as $name => $value) {
            if (\str_starts_with($name, 'SIG') && !\str_starts_with($name, 'SIG_') && \is_int($value)) {
                $signals[$value] ??= $name;
            }
        }
        foreach (['SIGABRT', 'SIGCHLD', 'SIGIO', 'SIGSYS'] as $name) {
            if (\defined($name)) {
                $value = \constant($name);
                if (\is_int($value)) {
                    $signals[$value] = $name;
                }
            }
        }
    }

    return $signals[$signal] ?? 'UNKNOWN';
}

function getEffectiveUser(): string
{
    return ProcessIdentity::getEffectiveUser();
}

function getEffectiveGroup(): string
{
    return ProcessIdentity::getEffectiveGroup();
}
