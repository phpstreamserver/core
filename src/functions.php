<?php

declare(strict_types=1);

namespace PHPStreamServer\Core;

use Amp\Socket\Socket;
use Amp\Socket\SocketException;
use PHPStreamServer\Core\Exception\UserChangeException;
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

function getCurrentUser(): string
{
    return (\posix_getpwuid(\posix_geteuid()) ?: [])['name'] ?? (string) \posix_geteuid();
}

function getCurrentGroup(): string
{
    return (\posix_getgrgid(\posix_getegid()) ?: [])['name'] ?? (string) \posix_getegid();
}

/**
 * @internal
 * @throws UserChangeException
 */
function setUserAndGroup(string|null $user = null, string|null $group = null): void
{
    if ($user === null && $group === null) {
        return;
    }

    $user ??= getCurrentUser();

    $userInfo = \ctype_digit($user) ? \posix_getpwuid((int) $user) : \posix_getpwnam($user);
    if ($userInfo === false) {
        throw new UserChangeException(\sprintf('User "%s" does not exist', $user));
    }

    $uid = $userInfo['uid'];
    $uname = $userInfo['name'];

    if ($group === null) {
        $gid = $userInfo['gid'];
    } else {
        $groupInfo = \ctype_digit($group) ? \posix_getgrgid((int) $group) : \posix_getgrnam($group);
        if ($groupInfo === false) {
            throw new UserChangeException(\sprintf('Group "%s" does not exist', $group));
        }
        $gid = $groupInfo['gid'];
    }

    if ($uid === \posix_geteuid() && $gid === \posix_getegid()) {
        return;
    }

    if (\posix_geteuid() !== 0) {
        throw new UserChangeException('You must have root privileges to change the user and group');
    }

    if (!\posix_setgid($gid)) {
        throw new UserChangeException(\sprintf('Changing GID to %d failed: %s', $gid, \posix_strerror(\posix_get_last_error())));
    }

    if (!\posix_initgroups($uname, $gid)) {
        throw new UserChangeException(\sprintf('Initializing supplementary groups for user "%s" failed: %s', $uname, \posix_strerror(\posix_get_last_error())));
    }

    if (!\posix_setuid($uid)) {
        throw new UserChangeException(\sprintf('Changing UID to %d failed: %s', $uid, \posix_strerror(\posix_get_last_error())));
    }
}

function humanFileSize(int $bytes): string
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

function reportErrors(): bool
{
    return (\error_reporting() & \E_ERROR) === \E_ERROR;
}

function isRunning(string $pidFile): bool
{
    return (0 !== $pid = getPid($pidFile)) && \posix_kill($pid, 0);
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
    return \sprintf('%s/%s%s.pid', getRunDirectory(), Server::SHORTNAME, \hash('xxh32', getStartFile()));
}

function getDefaultSocketFile(): string
{
    return \sprintf('%s/%s%s.socket', getRunDirectory(), Server::SHORTNAME, \hash('xxh32', getStartFile()));
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

function readExactly(Socket $socket, int $length): string
{
    $data = '';
    while (\strlen($data) < $length) {
        /** @psalm-suppress InvalidArgument */
        $chunk = $socket->read(limit: $length - \strlen($data));
        if ($chunk === null) {
            throw new SocketException(\sprintf('Socket closed after receiving %d of %d expected bytes', \strlen($data), $length));
        }
        $data .= $chunk;
    }

    return $data;
}

function strSignal(int $signal): string
{
    return match ($signal) {
        1 => 'SIGHUP',
        2 => 'SIGINT',
        3 => 'SIGQUIT',
        4 => 'SIGILL',
        5 => 'SIGTRAP',
        6 => 'SIGABRT',
        7 => 'SIGBUS',
        8 => 'SIGFPE',
        9 => 'SIGKILL',
        10 => 'SIGUSR1',
        11 => 'SIGSEGV',
        12 => 'SIGUSR2',
        13 => 'SIGPIPE',
        14 => 'SIGALRM',
        15 => 'SIGTERM',
        16 => 'SIGSTKFLT',
        17 => 'SIGCHLD',
        18 => 'SIGCONT',
        19 => 'SIGSTOP',
        20 => 'SIGTSTP',
        21 => 'SIGTTIN',
        22 => 'SIGTTOU',
        23 => 'SIGURG',
        24 => 'SIGXCPU',
        25 => 'SIGXFSZ',
        26 => 'SIGVTALRM',
        27 => 'SIGPROF',
        28 => 'SIGWINCH',
        29 => 'SIGIO',
        30 => 'SIGPWR',
        31 => 'SIGSYS',
        default => 'UNKNOWN',
    };
}
