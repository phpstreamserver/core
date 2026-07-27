<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Command;

use PHPStreamServer\Core\Exception\PHPStreamServerException;
use PHPStreamServer\Core\MessageBus\MessageInterface;
use PHPStreamServer\Core\WorkerInterface;

use function Opis\Closure\serialize as opisSerialize;
use function Opis\Closure\unserialize as opisUnserialize;

/**
 * Registers a worker and returns its assigned ID.
 *
 * @implements MessageInterface<int>
 */
final readonly class RegisterWorkerCommand implements MessageInterface
{
    public function __construct(public WorkerInterface $worker)
    {
    }

    public function __serialize(): array
    {
        if (!$this->worker->isSerializable()) {
            throw new PHPStreamServerException(\sprintf('%s cannot be registered through the message bus', $this->worker::class));
        }

        return ['worker' => opisSerialize($this->worker)];
    }

    public function __unserialize(array $data): void
    {
        $this->worker = opisUnserialize($data['worker']);
    }
}
