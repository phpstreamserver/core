<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Plugin\Supervisor\ReloadStrategy;

/**
 * Reload worker each time when exception occurs
 */
final class ExceptionReloadStrategy implements ReloadStrategy
{
    private array $allowedExceptions = [
        'Amp\Http\Server\HttpErrorException',
    ];

    /**
     * @param array<class-string<\Throwable>> $allowedExceptions exceptions that will not be trigger reloading
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
