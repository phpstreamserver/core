<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Message;

use PHPStreamServer\Core\MessageBus\MessageInterface;

/**
 * Sends multiple messages in a single message.
 *
 * @implements MessageInterface<void>
 */
final readonly class CompositeMessage implements MessageInterface
{
    public function __construct(
        /**
         * @var iterable<MessageInterface>
         */
        public iterable $messages,
    ) {
    }
}
