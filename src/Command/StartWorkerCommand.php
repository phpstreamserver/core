<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Command;

use PHPStreamServer\Core\MessageBus\AuthorizedSources;
use PHPStreamServer\Core\MessageBus\MessageInterface;
use PHPStreamServer\Core\MessageBus\MessageSource;

/**
 * Starts a worker using a registered WorkerFactory and returns its worker ID, or 0 if startup fails
 *
 * @implements MessageInterface<int>
 */
#[AuthorizedSources(MessageSource::MASTER, MessageSource::CHILD)]
final readonly class StartWorkerCommand implements MessageInterface
{
    /**
     * @param array<string, mixed> $parameters
     */
    public function __construct(public string $factoryId, public array $parameters = [])
    {
    }
}
