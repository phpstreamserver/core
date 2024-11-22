<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\MessageBus\Message;

use PHPStreamServer\Core\MessageBus\MessageInterface;

/**
 * @implements MessageInterface<null>
 */
final readonly class ReloadServerCommand implements MessageInterface
{
    public function __construct()
    {
    }
}
