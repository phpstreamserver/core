<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\MessageBus;

use Amp\Future;
use PHPStreamServer\Core\Exception\ServerIsNotRunning;

use function PHPStreamServer\Core\isRunning;

final class ExternalProcessMessageBus implements MessageBusInterface
{
    private MessageBusInterface|null $bus = null;

    public function __construct(private string $pidFile, private string $socketFile)
    {
    }

    /**
     * @template T
     * @param MessageInterface<T> $message
     * @return Future<T>
     * @throws ServerIsNotRunning
     */
    public function dispatch(MessageInterface $message): Future
    {
        if ($this->bus === null) {
            if ($this->pidFile === '' || $this->socketFile === '' || !isRunning($this->pidFile) || !\file_exists($this->socketFile)) {
                throw new ServerIsNotRunning();
            }

            $this->bus = new SocketFileMessageBus($this->socketFile);
        }

        return $this->bus->dispatch($message);
    }
}
