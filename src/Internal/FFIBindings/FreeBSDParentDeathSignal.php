<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Internal\FFIBindings;

use function PHPStreamServer\Core\strSignal;

/**
 * @internal
 */
final class FreeBSDParentDeathSignal
{
    private const P_PID = 0;
    private const PROC_PDEATHSIG_CTL = 11;

    private const CDEF = <<<'CDEF'
        typedef int idtype_t;
        typedef long long id_t;

        int procctl(idtype_t idtype, id_t id, int cmd, void *data);
        int *__error(void);
    CDEF;

    public static function set(int $signal): void
    {
        if (\PHP_OS !== 'FreeBSD') {
            throw new \RuntimeException(\sprintf('%s is only supported on FreeBSD', self::class));
        }

        $ffi = \FFI::cdef(self::CDEF);
        $signalData = $ffi->new('int');
        /** @psalm-suppress UndefinedPropertyAssignment */
        $signalData->cdata = $signal;

        if ($ffi->procctl(self::P_PID, 0, self::PROC_PDEATHSIG_CTL, \FFI::addr($signalData)) !== 0) {
            $errno = (int) $ffi->__error()[0];
            throw new \RuntimeException(\sprintf('Unable to set parent-death signal %s (%d): %s', strSignal($signal), $signal, \posix_strerror($errno)));
        }
    }
}
