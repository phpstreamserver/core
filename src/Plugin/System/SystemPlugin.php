<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Plugin\System;

use PHPStreamServer\Core\Command\GetNetworkInfoCommand;
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

        $handler = $this->masterContainer->getService(MessageHandlerInterface::class);

        $networkTrafficTracker = new NetworkTrafficTracker();
        $networkTrafficTracker->subscribeToWorkerMessages($handler);

        $handler->subscribe(GetServerStatusCommand::class, static function () use ($serverStatus): ServerStatus {
            return $serverStatus;
        });

        $handler->subscribe(GetNetworkInfoCommand::class, static function () use ($networkTrafficTracker): array {
            return $networkTrafficTracker->getProcessNetworkInfos();
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
