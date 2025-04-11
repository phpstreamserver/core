<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Plugin\System\Connections;

use Amp\Socket\InternetAddress;
use Amp\Socket\Socket;
use PHPStreamServer\Core\Message\CompositeMessage;
use PHPStreamServer\Core\Message\ConnectionClosedEvent;
use PHPStreamServer\Core\Message\ConnectionCreatedEvent;
use PHPStreamServer\Core\Message\RequestCounterIncreaseEvent;
use PHPStreamServer\Core\Message\RxCounterIncreaseEvent;
use PHPStreamServer\Core\Message\TxCounterIncreaseEvent;
use PHPStreamServer\Core\MessageBus\MessageBusInterface;
use PHPStreamServer\Core\MessageBus\MessageInterface;
use Revolt\EventLoop;

final class NetworkTrafficCounter
{
    private const FLUSH_PERIOD = 0.5;

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
     * @param positive-int $val
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
     * @param positive-int $val
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
     * @param positive-int $val
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
            $this->callbackId = EventLoop::delay(self::FLUSH_PERIOD, fn() => $this->flush());
        }
    }

    private function flush(): void
    {
        if ($this->callbackId !== '') {
            EventLoop::cancel($this->callbackId);
        }
        $queue = $this->queue;
        $this->queue = [];
        $this->callbackId = '';
        $this->messageBus->dispatch(new CompositeMessage($queue));
    }
}
