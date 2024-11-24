<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Plugin\System;

use PHPStreamServer\Core\MessageBus\MessageHandlerInterface;
use PHPStreamServer\Core\Plugin\Plugin;
use PHPStreamServer\Core\Plugin\System\Command\ConnectionsCommand;
use PHPStreamServer\Core\Plugin\System\Command\ReloadCommand;
use PHPStreamServer\Core\Plugin\System\Command\StartCommand;
use PHPStreamServer\Core\Plugin\System\Command\StatusCommand;
use PHPStreamServer\Core\Plugin\System\Command\StopCommand;
use PHPStreamServer\Core\Plugin\System\Command\WorkersCommand;
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
        $this->masterContainer->setService(ServerStatus::class, $serverStatus);

        $connectionsStatus = new ConnectionsStatus();
        $this->masterContainer->setService(ConnectionsStatus::class, $connectionsStatus);

        $handler = $this->masterContainer->getService(MessageHandlerInterface::class);
        $connectionsStatus->subscribeToWorkerMessages($handler);
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
