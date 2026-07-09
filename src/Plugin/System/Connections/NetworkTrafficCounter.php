<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Plugin\System\Connections;

use Amp\Socket\InternetAddress;
use Amp\Socket\Socket;
use PHPStreamServer\Core\Message\NetworkTrafficDeltaEvent;
use PHPStreamServer\Core\MessageBus\MessageBusInterface;
use Revolt\EventLoop;

final class NetworkTrafficCounter
{
    private const FLUSH_PERIOD = 2;

    private readonly int $pid;
    private int $nextConnectionId = 0;
    private bool $flushInProgress = false;

    /**
     * @var \WeakMap<Socket, int>
     */
    private \WeakMap $connectionIds;

    /**
     * @var array<int, Connection>
     */
    private array $createdConnections = [];

    /**
     * @var array<int>
     */
    private array $closedConnectionIds = [];

    /**
     * @var array<int, int>
     */
    private array $rxTrafficByConnection = [];

    /**
     * @var array<int, int>
     */
    private array $txTrafficByConnection = [];

    private int $requests = 0;

    public function __construct(private readonly MessageBusInterface $messageBus)
    {
        $this->pid = \posix_getpid();
        /** @var \WeakMap<Socket, int> */
        $this->connectionIds = new \WeakMap();

        EventLoop::unreference(EventLoop::repeat(self::FLUSH_PERIOD, $this->flush(...)));
    }

    public function addConnection(Socket $socket): void
    {
        $connectionId = $this->getConnectionId($socket);
        $localAddress = $socket->getLocalAddress();
        $remoteAddress = $socket->getRemoteAddress();
        \assert($localAddress instanceof InternetAddress);
        \assert($remoteAddress instanceof InternetAddress);

        $this->createdConnections[$connectionId] = new Connection(
            pid: $this->pid,
            connectedAt: new \DateTimeImmutable('now'),
            localIp: $localAddress->getAddress(),
            localPort: (string) $localAddress->getPort(),
            remoteIp: $remoteAddress->getAddress(),
            remotePort: (string) $remoteAddress->getPort(),
        );
    }

    public function removeConnection(Socket $socket): void
    {
        $connectionId = $this->getConnectionId($socket);
        $this->closedConnectionIds[] = $connectionId;
        $this->connectionIds->offsetUnset($socket);
    }

    /**
     * @param int<0, max> $val
     */
    public function incRx(Socket $socket, int $val): void
    {
        $connectionId = $this->getConnectionId($socket);
        $this->rxTrafficByConnection[$connectionId] ??= 0;
        $this->rxTrafficByConnection[$connectionId] += $val;
    }

    /**
     * @param int<0, max> $val
     */
    public function incTx(Socket $socket, int $val): void
    {
        $connectionId = $this->getConnectionId($socket);
        $this->txTrafficByConnection[$connectionId] ??= 0;
        $this->txTrafficByConnection[$connectionId] += $val;
    }

    /**
     * @param int<0, max> $val
     */
    public function incRequests(int $val = 1): void
    {
        $this->requests += $val;
    }

    private function getConnectionId(Socket $socket): int
    {
        if (!$this->connectionIds->offsetExists($socket)) {
            $this->connectionIds->offsetSet($socket, ++$this->nextConnectionId);
        }

        return $this->connectionIds->offsetGet($socket);
    }

    private function flush(): void
    {
        $hasPendingEvents = $this->createdConnections !== []
            || $this->closedConnectionIds !== []
            || $this->rxTrafficByConnection !== []
            || $this->txTrafficByConnection !== []
            || $this->requests !== 0
        ;

        if ($this->flushInProgress || !$hasPendingEvents) {
            return;
        }

        $createdConnections = $this->createdConnections;
        $closedConnectionIds = $this->closedConnectionIds;
        $rxTrafficByConnection = $this->rxTrafficByConnection;
        $txTrafficByConnection = $this->txTrafficByConnection;
        $requests = $this->requests;

        $this->createdConnections = [];
        $this->closedConnectionIds = [];
        $this->rxTrafficByConnection = [];
        $this->txTrafficByConnection = [];
        $this->requests = 0;
        $this->flushInProgress = true;

        $this->messageBus->dispatch(new NetworkTrafficDeltaEvent(
            pid: $this->pid,
            createdConnections: $createdConnections,
            closedConnectionIds: $closedConnectionIds,
            rxTrafficByConnection: $rxTrafficByConnection,
            txTrafficByConnection: $txTrafficByConnection,
            requests: $requests,
        ))->finally(function (): void {
            $this->flushInProgress = false;
        });
    }
}
