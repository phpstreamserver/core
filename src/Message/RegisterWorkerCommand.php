<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Message;

use PHPStreamServer\Core\Exception\PHPStreamServerException;
use PHPStreamServer\Core\MessageBus\MessageInterface;
use PHPStreamServer\Core\Process;
use PHPStreamServer\Symfony\Worker\SymfonyHttpServerProcess;

use function Opis\Closure\serialize as opisSerialize;
use function Opis\Closure\unserialize as opisUnserialize;

/**
 * Registers and starts a new worker. Returns the unique worker ID.
 *
 * @implements MessageInterface<int>
 */
final readonly class RegisterWorkerCommand implements MessageInterface
{
    public function __construct(public Process $workerProcess)
    {
    }

    public function __serialize(): array
    {
        if ($this->workerProcess::class === SymfonyHttpServerProcess::class) {
            throw new PHPStreamServerException('SymfonyHttpServerProcess cannot be registered through the message bus');
        }

        return ['workerProcess' => opisSerialize($this->workerProcess)];
    }

    public function __unserialize(array $data): void
    {
        $this->workerProcess = opisUnserialize($data['workerProcess']);
    }
}
