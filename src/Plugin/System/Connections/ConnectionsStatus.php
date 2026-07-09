<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Plugin\System\Connections;

use PHPStreamServer\Core\Message\NetworkTrafficDeltaEvent;
use PHPStreamServer\Core\Message\ProcessDetachedEvent;
use PHPStreamServer\Core\Message\ProcessExitEvent;
use PHPStreamServer\Core\Message\ProcessSpawnedEvent;
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

        $handler->subscribe(ProcessExitEvent::class, static function (ProcessExitEvent $message) use (&$processConnections): void {
            unset($processConnections[$message->pid]);
        });

        $handler->subscribe(ProcessDetachedEvent::class, static function (ProcessDetachedEvent $message) use (&$processConnections): void {
            unset($processConnections[$message->pid]);
        });

        $handler->subscribe(NetworkTrafficDeltaEvent::class, static function (NetworkTrafficDeltaEvent $message) use (&$processConnections): void {
            if (!isset($processConnections[$message->pid])) {
                return;
            }

            $processConnection = $processConnections[$message->pid];

            $processConnection->requests += $message->requests;

            foreach ($message->createdConnections as $connectionId => $connection) {
                $processConnection->connections[$connectionId] = $connection;
            }

            foreach ($message->rxTrafficByConnection as $connectionId => $bytes) {
                if (isset($processConnection->connections[$connectionId])) {
                    $processConnection->connections[$connectionId]->rx += $bytes;
                }
                $processConnection->rx += $bytes;
            }

            foreach ($message->txTrafficByConnection as $connectionId => $bytes) {
                if (isset($processConnection->connections[$connectionId])) {
                    $processConnection->connections[$connectionId]->tx += $bytes;
                }
                $processConnection->tx += $bytes;
            }

            foreach ($message->closedConnectionIds as $connectionId) {
                unset($processConnection->connections[$connectionId]);
            }
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
