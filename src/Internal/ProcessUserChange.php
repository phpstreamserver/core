<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Internal;

use PHPStreamServer\Core\Exception\UserChangeException;

use function PHPStreamServer\Core\getCurrentUser;

/**
 * @internal
 */
trait ProcessUserChange
{
    /**
     * @throws UserChangeException
     */
    private function setUserAndGroup(string|null $user = null, string|null $group = null): void
    {
        if ($user === null && $group === null) {
            return;
        }

        if (\posix_getuid() !== 0) {
            throw new UserChangeException('You must have root privileges to change the user and group');
        }

        $user ??= getCurrentUser();

        // Get uid
        if ($userInfo = \posix_getpwnam($user)) {
            $uid = $userInfo['uid'];
        } else {
            throw new UserChangeException(\sprintf('User "%s" does not exist', $user));
        }

        // Get gid
        if ($group === null) {
            $gid = $userInfo['gid'];
        } elseif ($groupInfo = \posix_getgrnam($group)) {
            $gid = $groupInfo['gid'];
        } else {
            throw new UserChangeException(\sprintf('Group "%s" does not exist', $group));
        }

        // Set uid and gid
        if ($uid !== \posix_getuid() || $gid !== \posix_getgid()) {
            if (!\posix_setgid($gid) || !\posix_initgroups($userInfo['name'], $gid) || !\posix_setuid($uid)) {
                throw new UserChangeException('Changing guid or uid fails');
            }
        }
    }
}
