<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Internal\CloseOnExec;

final readonly class CloseOnExec
{
    private function __construct()
    {
    }

    public static function set(): void
    {
        if (\PHP_OS_FAMILY === 'Linux') {
            LinuxCloseOnExec::set();
        } elseif (\PHP_OS_FAMILY === 'Darwin') {
            DarwinCloseOnExec::set();
        } elseif (\PHP_OS_FAMILY === 'BSD' && \PHP_OS === 'FreeBSD') {
            FreeBSDCloseOnExec::set();
        } elseif (\PHP_OS_FAMILY === 'BSD' && \PHP_OS === 'OpenBSD') {
            OpenBSDCloseOnExec::set();
        }
    }
}
