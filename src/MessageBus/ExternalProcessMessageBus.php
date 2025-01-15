<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\MessageBus;

use Amp\Future;
use PHPStreamServer\Core\Exception\ServerIsNotRunning;
use function PHPStreamServer\Core\isRunning;

final class ExternalProcessMessageBus implements MessageBusInterface
{
    private MessageBusInterface $bus;

    /**
     * @throws ServerIsNotRunning
     */
    public function __construct(string $pidFile, string $socketFile)
    {
        if ($pidFile === '' || $socketFile === '' || !isRunning($pidFile) || !\file_exists($socketFile)) {
            throw new ServerIsNotRunning();
        }

        $this->bus = new SocketFileMessageBus($socketFile);
    }

    /**
     * @template T
     * @param MessageInterface<T> $message
     * @return Future<T>
     */
    public function dispatch(MessageInterface $message): Future
    {
        return $this->bus->dispatch($message);
    }
}
