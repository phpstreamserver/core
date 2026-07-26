<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Plugin\System;

use PHPStreamServer\Core\Event\NetworkTrafficDeltaEvent;
use PHPStreamServer\Core\Event\ProcessExitEvent;
use PHPStreamServer\Core\Event\ProcessReplacedEvent;
use PHPStreamServer\Core\Event\ProcessSpawnedEvent;
use PHPStreamServer\Core\MessageBus\MessageHandlerInterface;

final class NetworkTrafficTracker
{
    /**
     * @var array<int, ProcessNetworkInfo>
     */
    private array $processNetworkInfos = [];

    public function __construct()
    {
    }

    public function subscribeToWorkerMessages(MessageHandlerInterface $handler): void
    {
        $processConnections = &$this->processNetworkInfos;

        $handler->subscribe(ProcessSpawnedEvent::class, static function (ProcessSpawnedEvent $message) use (&$processConnections): void {
            $processConnections[$message->pid] = new ProcessNetworkInfo(
                pid: $message->pid,
                name: $message->name,
            );
        });

        $handler->subscribe(ProcessExitEvent::class, static function (ProcessExitEvent $message) use (&$processConnections): void {
            unset($processConnections[$message->pid]);
        });

        $handler->subscribe(ProcessReplacedEvent::class, static function (ProcessReplacedEvent $message) use (&$processConnections): void {
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

    public function getProcessNetworkInfos(): array
    {
        return $this->processNetworkInfos;
    }

    /**
     * @return list<Connection>
     */
    public function getActiveConnections(): array
    {
        return \array_merge(...\array_map(static fn(ProcessNetworkInfo $p) => $p->connections, $this->processNetworkInfos));
    }
}
