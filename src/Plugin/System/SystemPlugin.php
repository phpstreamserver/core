<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Plugin\System;

use PHPStreamServer\Core\Command\GetConnectionsStatusCommand;
use PHPStreamServer\Core\Command\GetServerStatusCommand;
use PHPStreamServer\Core\ConsoleCommand\ConnectionsCommand;
use PHPStreamServer\Core\ConsoleCommand\ReloadCommand;
use PHPStreamServer\Core\ConsoleCommand\StartCommand;
use PHPStreamServer\Core\ConsoleCommand\StatusCommand;
use PHPStreamServer\Core\ConsoleCommand\StopCommand;
use PHPStreamServer\Core\MessageBus\MessageHandlerInterface;
use PHPStreamServer\Core\Plugin\Plugin;
use PHPStreamServer\Core\WorkerInterface;

use function PHPStreamServer\Core\getDriverName;
use function PHPStreamServer\Core\getStartFile;

/**
 * @internal
 * @extends Plugin<WorkerInterface>
 */
final class SystemPlugin extends Plugin
{
    public function __construct(
        private readonly int $stopTimeout,
    ) {
    }

    protected function beforeStart(): void
    {
        $this->masterContainer->setParameter('stop_timeout', $this->stopTimeout);
    }

    public function onStart(): void
    {
        $serverStatus = new ServerStatus(
            eventLoop: getDriverName(),
            startFile: getStartFile(),
            startedAt: new \DateTimeImmutable('now'),
        );

        $connectionsStatus = new ConnectionsStatus();

        $this->masterContainer->setService(ServerStatus::class, $serverStatus);
        $this->masterContainer->setService(ConnectionsStatus::class, $connectionsStatus);

        $handler = $this->masterContainer->getService(MessageHandlerInterface::class);
        $connectionsStatus->subscribeToWorkerMessages($handler);

        $handler->subscribe(GetServerStatusCommand::class, static function () use ($serverStatus): ServerStatus {
            return $serverStatus;
        });

        $handler->subscribe(GetConnectionsStatusCommand::class, static function () use ($connectionsStatus): ConnectionsStatus {
            return $connectionsStatus;
        });
    }

    public function registerCommands(): array
    {
        return [
            new StartCommand(),
            new StopCommand(),
            new ReloadCommand(),
            new StatusCommand(),
            new ConnectionsCommand(),
        ];
    }
}
