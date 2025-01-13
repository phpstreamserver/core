<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Plugin\System;

use PHPStreamServer\Core\Command\ConnectionsCommand;
use PHPStreamServer\Core\Command\ReloadCommand;
use PHPStreamServer\Core\Command\StartCommand;
use PHPStreamServer\Core\Command\StatusCommand;
use PHPStreamServer\Core\Command\StopCommand;
use PHPStreamServer\Core\Command\WorkersCommand;
use PHPStreamServer\Core\Message\GetConnectionsStatusCommand;
use PHPStreamServer\Core\Message\GetServerStatusCommand;
use PHPStreamServer\Core\MessageBus\MessageHandlerInterface;
use PHPStreamServer\Core\Plugin\Plugin;
use PHPStreamServer\Core\Plugin\System\Connections\ConnectionsStatus;
use PHPStreamServer\Core\Plugin\System\Status\ServerStatus;

/**
 * @internal
 */
final class SystemPlugin extends Plugin
{
    public function __construct()
    {
    }

    public function onStart(): void
    {
        $serverStatus = new ServerStatus();
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
            new WorkersCommand(),
            new ConnectionsCommand(),
        ];
    }
}
