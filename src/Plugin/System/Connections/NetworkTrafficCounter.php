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
    private const FLUSH_PERIOD = 0.3;

    /**
     * @var list<MessageInterface>
     */
    private array $events = [];

    public function __construct(MessageBusInterface $messageBus)
    {
        EventLoop::repeat(self::FLUSH_PERIOD, function () use ($messageBus) {
            $events = $this->events;
            if ($events !== []) {
                $this->events = [];
                $messageBus->dispatch(new CompositeMessage($events));
            }
        });
    }

    public function addConnection(Socket $socket): void
    {
        $localAddress = $socket->getLocalAddress();
        $remoteAddress = $socket->getRemoteAddress();
        \assert($localAddress instanceof InternetAddress);
        \assert($remoteAddress instanceof InternetAddress);

        $this->events[] = new ConnectionCreatedEvent(
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
        );
    }

    public function removeConnection(Socket $socket): void
    {
        $this->events[] = new ConnectionClosedEvent(
            pid: \posix_getpid(),
            connectionId: \spl_object_id($socket),
        );
    }

    /**
     * @param int<0, max> $val
     */
    public function incRx(Socket $socket, int $val): void
    {
        $this->events[] = new RxCounterIncreaseEvent(
            pid: \posix_getpid(),
            connectionId: \spl_object_id($socket),
            rx: $val,
        );
    }

    /**
     * @param int<0, max> $val
     */
    public function incTx(Socket $socket, int $val): void
    {
        $this->events[] = new TxCounterIncreaseEvent(
            pid: \posix_getpid(),
            connectionId: \spl_object_id($socket),
            tx: $val,
        );
    }

    /**
     * @param int<0, max> $val
     */
    public function incRequests(int $val = 1): void
    {
        $this->events[] = new RequestCounterIncreaseEvent(
            pid: \posix_getpid(),
            requests: $val,
        );
    }
}
