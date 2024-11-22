<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Plugin\Supervisor\Message;

use PHPStreamServer\Core\MessageBus\MessageInterface;

/**
 * @implements MessageInterface<null>
 */
final readonly class ProcessExitEvent implements MessageInterface
{
    public function __construct(
        public int $pid,
        public int $exitCode,
    ) {
    }
}
