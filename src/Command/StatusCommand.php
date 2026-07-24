<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Command;

use PHPStreamServer\Core\Console\Command;
use PHPStreamServer\Core\Console\Table;
use PHPStreamServer\Core\Message\GetProcessesCommand;
use PHPStreamServer\Core\Message\GetServerStatusCommand;
use PHPStreamServer\Core\Message\GetWorkersCommand;
use PHPStreamServer\Core\MessageBus\SocketFileMessageBus;
use PHPStreamServer\Core\Plugin\Supervisor\ProcessInfo;
use PHPStreamServer\Core\Plugin\Supervisor\WorkerInfo;
use PHPStreamServer\Core\Plugin\System\Status\ServerStatus;
use PHPStreamServer\Core\Server;

use function PHPStreamServer\Core\getDriverName;
use function PHPStreamServer\Core\getStartFile;
use function PHPStreamServer\Core\humanFileSize;
use function PHPStreamServer\Core\isRunning;

class StatusCommand extends Command
{
    final public static function getName(): string
    {
        return 'status';
    }

    final public static function getDescription(): string
    {
        return 'Show server status';
    }

    public function execute(string $pidFile, string $socketFile): int
    {
        $isRunning = isRunning($pidFile);
        $eventLoop = getDriverName();
        $startFile = getStartFile();

        if ($isRunning) {
            $bus = new SocketFileMessageBus($socketFile);

            /** @var ServerStatus $serverStatus */
            $serverStatus = $bus->dispatch(new GetServerStatusCommand())->await();

            /** @var array<WorkerInfo> $workerInfos */
            $workerInfos = $bus->dispatch(new GetWorkersCommand())->await();

            /** @var array<ProcessInfo> $processInfos */
            $processInfos = $bus->dispatch(new GetProcessesCommand())->await();

            $startedAt = $serverStatus->startedAt;
            $workersCount = \count($workerInfos);
            $processesCount = \count($processInfos);
            $totalMemory = \array_sum(\array_map(static fn(ProcessInfo $p): int => $p->memory, $processInfos));
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
                ['Worker count:', $workersCount],
                ['Process count:', $processesCount],
                ['Memory usage:', humanFileSize($totalMemory)],
            ]];
        }

        echo (new Table(indent: 1))->addRows($rows);

        return 0;
    }
}
