<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\ReloadStrategy;

/**
 * Reloads the worker whenever an exception is thrown
 */
final class ExceptionReloadStrategy implements ReloadStrategy
{
    private array $allowedExceptions = [
        \CompileError::class,
        'Amp\Http\Server\HttpErrorException',
        'Symfony\Component\HttpKernel\Exception\HttpException',
    ];

    /**
     * @param array<class-string<\Throwable>> $allowedExceptions exceptions that will not trigger a reload
     */
    public function __construct(array $allowedExceptions = [])
    {
        $this->allowedExceptions = [...$this->allowedExceptions, ...$allowedExceptions];
    }

    public function shouldReload(mixed $eventObject = null): bool
    {
        if (!$eventObject instanceof \Throwable) {
            return false;
        }

        foreach ($this->allowedExceptions as $allowedExceptionClass) {
            if ($eventObject instanceof $allowedExceptionClass) {
                return false;
            }
        }

        return true;
    }
}
