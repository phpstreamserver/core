<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\MessageBus\Message;

use PHPStreamServer\Core\MessageBus\MessageInterface;

/**
 * @implements MessageInterface<null>
 */
final readonly class ContainerSetCommand implements MessageInterface
{
    public function __construct(public string $id, public mixed $value)
    {
    }
}
