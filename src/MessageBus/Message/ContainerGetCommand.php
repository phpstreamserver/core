<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\MessageBus\Message;

use PHPStreamServer\Core\MessageBus\MessageInterface;

/**
 * @implements MessageInterface<mixed>
 */
final readonly class ContainerGetCommand implements MessageInterface
{
    public function __construct(public string $id)
    {
    }
}
