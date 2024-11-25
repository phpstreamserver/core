<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Plugin\System\Connections;

use Amp\Socket\InternetAddress;
use Amp\Socket\Socket;
use PHPStreamServer\Core\MessageBus\Message\CompositeMessage;
use PHPStreamServer\Core\MessageBus\MessageBusInterface;
use PHPStreamServer\Core\MessageBus\MessageInterface;
use PHPStreamServer\Core\Plugin\System\Message\ConnectionClosedEvent;
use PHPStreamServer\Core\Plugin\System\Message\ConnectionCreatedEvent;
use PHPStreamServer\Core\Plugin\System\Message\RequestCounterIncreaseEvent;
use PHPStreamServer\Core\Plugin\System\Message\RxCounterIncreaseEvent;
use PHPStreamServer\Core\Plugin\System\Message\TxCounterIncreaseEvent;
use Revolt\EventLoop;

final class NetworkTrafficCounter
{
    private const MAX_FLUSH_TIME = 0.5;

    /**
     * @var list<MessageInterface>
     */
    private array $queue = [];
    private string $callbackId = '';

    public function __construct(private readonly MessageBusInterface $messageBus)
    {
    }

    public function addConnection(Socket $socket): void
    {
        $localAddress = $socket->getLocalAddress();
        $remoteAddress = $socket->getRemoteAddress();
        \assert($localAddress instanceof InternetAddress);
        \assert($remoteAddress instanceof InternetAddress);

        $this->queue(new ConnectionCreatedEvent(
            pid: \posix_getpid(),
            connectionId: \spl_object_id($socket),
            connection: new Connection(
                pid: \posix_getpid(),
                connectedAt: new \DateTimeImmutable('now'),
                localIp: $localAddress->getAddress(),
                localPort: (string) $localAddress->getPort(),
                remoteIp: $remoteAddress->getAddress(),
                remotePort: (string) $remoteAddress->getPort(),
            ),
        ));
    }

    public function removeConnection(Socket $socket): void
    {
        $this->queue(new ConnectionClosedEvent(
            pid: \posix_getpid(),
            connectionId: \spl_object_id($socket),
        ));
    }

    /**
     * @param int<0, max> $val
     */
    public function incRx(Socket $socket, int $val): void
    {
        $this->queue(new RxCounterIncreaseEvent(
            pid: \posix_getpid(),
            connectionId: \spl_object_id($socket),
            rx: $val,
        ));
    }

    /**
     * @param int<0, max> $val
     */
    public function incTx(Socket $socket, int $val): void
    {
        $this->queue(new TxCounterIncreaseEvent(
            pid: \posix_getpid(),
            connectionId: \spl_object_id($socket),
            tx: $val,
        ));
    }

    /**
     * @param int<0, max> $val
     */
    public function incRequests(int $val = 1): void
    {
        $this->queue(new RequestCounterIncreaseEvent(
            pid: \posix_getpid(),
            requests: $val,
        ));
    }

    private function queue(MessageInterface $message): void
    {
        $this->queue[] = $message;

        if ($this->callbackId === '') {
            $this->callbackId = EventLoop::delay(self::MAX_FLUSH_TIME, fn() => $this->flush());
        }
    }

    private function flush(): void
    {
        $queue = $this->queue;
        EventLoop::cancel($this->callbackId);
        $this->queue = [];
        $this->callbackId = '';
        $this->messageBus->dispatch(new CompositeMessage($queue));
    }
}
