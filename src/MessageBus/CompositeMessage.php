<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\MessageBus;

/**
 * Sends multiple messages at once.
 *
 * @implements MessageInterface<void>
 */
#[AuthorizedSources(MessageSource::MASTER, MessageSource::CHILD, MessageSource::MANAGER)]
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
