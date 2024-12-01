<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Plugin\Supervisor\ReloadStrategy;

interface ReloadStrategy
{
    /**
     * If the method returns true, the worker should be reloaded immediately.
     *
     * @param mixed $eventObject could be a request object, exception object, or null, depending on the eventCode.
     */
    public function shouldReload(mixed $eventObject = null): bool;
}
