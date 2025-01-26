<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Command;

use PHPStreamServer\Core\Console\Command;
use PHPStreamServer\Core\Console\Table;
use PHPStreamServer\Core\Message\GetServerStatusCommand;
use PHPStreamServer\Core\Message\GetSupervisorStatusCommand;
use PHPStreamServer\Core\MessageBus\SocketFileMessageBus;
use PHPStreamServer\Core\Plugin\Supervisor\Status\SupervisorStatus;
use PHPStreamServer\Core\Plugin\System\Status\ServerStatus;
use PHPStreamServer\Core\Server;

use function PHPStreamServer\Core\getDriverName;
use function PHPStreamServer\Core\getStartFile;
use function PHPStreamServer\Core\humanFileSize;
use function PHPStreamServer\Core\isRunning;

class StatusCommand extends Command
{
    final public const COMMAND = 'status';
    final public const DESCRIPTION = 'Show server status';

    public function execute(array $args): int
    {
        /**
         * @var array{pidFile: string, socketFile: string} $args
         */

        $isRunning = isRunning($args['pidFile']);
        $eventLoop = getDriverName();
        $startFile = getStartFile();

        if ($isRunning) {
            $bus = new SocketFileMessageBus($args['socketFile']);
            $serverStatus = $bus->dispatch(new GetServerStatusCommand())->await();
            \assert($serverStatus instanceof ServerStatus);
            $supervosorStatus = $bus->dispatch(new GetSupervisorStatusCommand())->await();
            \assert($supervosorStatus instanceof SupervisorStatus);

            $startedAt = $serverStatus->startedAt;
            $workersCount = $supervosorStatus->getWorkersCount();
            $processesCount = $supervosorStatus->getProcessesCount();
            $totalMemory = $supervosorStatus->getTotalMemory();
        } else {
            $startedAt = new \DateTimeImmutable();
            $workersCount = 0;
            $processesCount = 0;
            $totalMemory = 0;
        }

        echo ($isRunning ? '<color;fg=green>●</> ' : '● ') . Server::TITLE . "\n";

        $rows = [
            [Server::NAME . ' version:', Server::getVersion()],
            ['PHP version:', PHP_VERSION],
            ['Event loop driver:', $eventLoop],
            ['Start file:', $startFile],
            ['Status:', $isRunning
                ? '<color;fg=green>active</> since ' . $startedAt->format(\DateTimeInterface::RFC7231)
                : 'inactive',
            ],
        ];

        if ($isRunning) {
            $rows = [...$rows, ...[
                ['Workers count:', $workersCount],
                ['Processes count:', $processesCount],
                ['Memory usage:', humanFileSize($totalMemory)],
            ]];
        }

        echo (new Table(indent: 1))->addRows($rows);

        return 0;
    }
}
