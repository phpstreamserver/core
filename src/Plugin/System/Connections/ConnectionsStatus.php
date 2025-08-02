<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Plugin\System\Connections;

use PHPStreamServer\Core\Message\ConnectionClosedEvent;
use PHPStreamServer\Core\Message\ConnectionCreatedEvent;
use PHPStreamServer\Core\Message\ProcessDetachedEvent;
use PHPStreamServer\Core\Message\ProcessSpawnedEvent;
use PHPStreamServer\Core\Message\RequestCounterIncreaseEvent;
use PHPStreamServer\Core\Message\RxCounterIncreaseEvent;
use PHPStreamServer\Core\Message\TxCounterIncreaseEvent;
use PHPStreamServer\Core\MessageBus\MessageHandlerInterface;

final class ConnectionsStatus
{
    /**
     * @var array<int, ProcessConnectionsInfo>
     */
    private array $processConnections = [];

    public function __construct()
    {
    }

    public function subscribeToWorkerMessages(MessageHandlerInterface $handler): void
    {
        $processConnections = &$this->processConnections;

        $handler->subscribe(ProcessSpawnedEvent::class, static function (ProcessSpawnedEvent $message) use (&$processConnections): void {
            $processConnections[$message->pid] = new ProcessConnectionsInfo(
                pid: $message->pid,
            );
        });

        $handler->subscribe(ProcessDetachedEvent::class, static function (ProcessDetachedEvent $message) use (&$processConnections): void {
            unset($processConnections[$message->pid]);
        });

        $handler->subscribe(RxCounterIncreaseEvent::class, static function (RxCounterIncreaseEvent $message) use (&$processConnections): void {
            $processConnection = $processConnections[$message->pid];
            if (isset($processConnection->connections[$message->connectionId])) {
                $processConnection->connections[$message->connectionId]->rx += $message->rx;
            }
            $processConnection->rx += $message->rx;
        });

        $handler->subscribe(TxCounterIncreaseEvent::class, static function (TxCounterIncreaseEvent $message) use (&$processConnections): void {
            $processConnection = $processConnections[$message->pid];
            if (isset($processConnection->connections[$message->connectionId])) {
                $processConnection->connections[$message->connectionId]->tx += $message->tx;
            }
            $processConnection->tx += $message->tx;
        });

        $handler->subscribe(RequestCounterIncreaseEvent::class, static function (RequestCounterIncreaseEvent $message) use (&$processConnections): void {
            $processConnections[$message->pid]->requests += $message->requests;
        });

        $handler->subscribe(ConnectionCreatedEvent::class, static function (ConnectionCreatedEvent $message) use (&$processConnections): void {
            $processConnections[$message->pid]->connections[$message->connectionId] = $message->connection;
        });

        $handler->subscribe(ConnectionClosedEvent::class, static function (ConnectionClosedEvent $message) use (&$processConnections): void {
            unset($processConnections[$message->pid]->connections[$message->connectionId]);
        });
    }

    /**
     * @return list<ProcessConnectionsInfo>
     */
    public function getProcessesConnectionsInfo(): array
    {
        return \array_values($this->processConnections);
    }

    public function getProcessConnectionsInfo(int $pid): ProcessConnectionsInfo
    {
        return $this->processConnections[$pid] ?? new ProcessConnectionsInfo(pid: $pid);
    }

    /**
     * @return list<Connection>
     */
    public function getActiveConnections(): array
    {
        return \array_merge(...\array_map(static fn(ProcessConnectionsInfo $p) => $p->connections, $this->processConnections));
    }
}
