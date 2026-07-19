<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Message;

use PHPStreamServer\Core\MessageBus\MessageInterface;

/**
 * Unregister worker.
 *
 * @implements MessageInterface<null>
 */
final readonly class UnRegisterWorkerCommand implements MessageInterface
{
    public function __construct(public int $workerId)
    {
    }
}
