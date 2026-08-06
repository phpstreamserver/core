<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Runtime;

use PHPStreamServer\Core\Exception\ProcessIdentityException;

final class ProcessIdentity
{
    private function __construct()
    {
    }

    public static function getEffectiveUser(): string
    {
        return (\posix_getpwuid(\posix_geteuid()) ?: [])['name'] ?? (string) \posix_geteuid();
    }

    public static function getEffectiveGroup(): string
    {
        return (\posix_getgrgid(\posix_getegid()) ?: [])['name'] ?? (string) \posix_getegid();
    }

    /**
     * @throws ProcessIdentityException
     */
    public static function switchTo(string|null $user = null, string|null $group = null): void
    {
        if ($user === null && $group === null) {
            return;
        }

        $user ??= self::getEffectiveUser();

        $userInfo = \ctype_digit($user) ? \posix_getpwuid((int) $user) : \posix_getpwnam($user);
        if ($userInfo === false) {
            throw new ProcessIdentityException(\sprintf('User "%s" does not exist', $user));
        }

        $uid = $userInfo['uid'];
        $uname = $userInfo['name'];

        if ($group === null) {
            $gid = $userInfo['gid'];
        } else {
            $groupInfo = \ctype_digit($group) ? \posix_getgrgid((int) $group) : \posix_getgrnam($group);
            if ($groupInfo === false) {
                throw new ProcessIdentityException(\sprintf('Group "%s" does not exist', $group));
            }
            $gid = $groupInfo['gid'];
        }

        if ($uid === \posix_geteuid() && $gid === \posix_getegid()) {
            return;
        }

        if (\posix_geteuid() !== 0) {
            throw new ProcessIdentityException('You must have root privileges to change the user and group');
        }

        if (!\posix_setgid($gid)) {
            throw new ProcessIdentityException(\sprintf('Changing GID to %d failed: %s', $gid, \posix_strerror(\posix_get_last_error())));
        }

        if (!\posix_initgroups($uname, $gid)) {
            throw new ProcessIdentityException(\sprintf('Initializing supplementary groups for user "%s" failed: %s', $uname, \posix_strerror(\posix_get_last_error())));
        }

        if (!\posix_setuid($uid)) {
            throw new ProcessIdentityException(\sprintf('Changing UID to %d failed: %s', $uid, \posix_strerror(\posix_get_last_error())));
        }
    }
}
