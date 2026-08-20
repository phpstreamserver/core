<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Internal;

use PHPStreamServer\Core\Internal\FFIBindings\DarwinPeerCredentials;
use PHPStreamServer\Core\Internal\FFIBindings\FreeBSDPeerCredentials;
use PHPStreamServer\Core\Internal\FFIBindings\LinuxPeerCredentials;
use PHPStreamServer\Core\Internal\FFIBindings\NetBSDPeerCredentials;
use PHPStreamServer\Core\Internal\FFIBindings\OpenBSDPeerCredentials;
use PHPStreamServer\Core\Internal\FFIBindings\SolarisPeerCredentials;
use PHPStreamServer\Core\Internal\FFIBindings\StreamFileno;

/**
 * @internal
 */
final readonly class PeerCredentials
{
    public function __construct(
        public int $pid,
        public int $uid,
        public int $gid,
    ) {
    }

    /**
     * @param resource $resource
     */
    public static function get(mixed $resource): self|null
    {
        try {
            $fd = StreamFileno::get($resource);
        } catch (\InvalidArgumentException) {
            return null;
        }

        if ($fd === null || $fd < 0) {
            return null;
        }

        try {
            $credentials = match (\PHP_OS_FAMILY) {
                'Linux' => LinuxPeerCredentials::get($fd),
                'Darwin' => DarwinPeerCredentials::get($fd),
                'Solaris' => SolarisPeerCredentials::get($fd),
                'BSD' => match (\PHP_OS) {
                    'FreeBSD' => FreeBSDPeerCredentials::get($fd),
                    'OpenBSD' => OpenBSDPeerCredentials::get($fd),
                    'NetBSD' => NetBSDPeerCredentials::get($fd),
                    default => null,
                },
                default => null,
            };
        } catch (\RuntimeException $e) {
            \trigger_error($e->getMessage(), E_USER_WARNING);
            return null;
        }

        if ($credentials === null) {
            return null;
        }

        return new self($credentials[0], $credentials[1], $credentials[2]);
    }
}
