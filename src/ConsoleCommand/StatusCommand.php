<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\ConsoleCommand;

use PHPStreamServer\Core\Command\GetProcessesCommand;
use PHPStreamServer\Core\Command\GetServerStatusCommand;
use PHPStreamServer\Core\Command\GetWorkersCommand;
use PHPStreamServer\Core\Console\Command;
use PHPStreamServer\Core\Console\Table;
use PHPStreamServer\Core\Internal\MessageBus\SocketFileMessageBus;
use PHPStreamServer\Core\Plugin\Supervisor\ProcessInfo;
use PHPStreamServer\Core\Plugin\Supervisor\WorkerInfo;
use PHPStreamServer\Core\Plugin\System\ServerStatus;
use PHPStreamServer\Core\Server;

use function PHPStreamServer\Core\formatFileSize;
use function PHPStreamServer\Core\getDriverName;
use function PHPStreamServer\Core\getStartFile;
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
        $startedRows = [];
        $runtimeRows = [];

        if ($isRunning) {
            $bus = new SocketFileMessageBus($socketFile);

            /** @var ServerStatus $serverStatus */
            $serverStatus = $bus->dispatch(new GetServerStatusCommand())->await();

            /** @var array<WorkerInfo> $workerInfos */
            $workerInfos = $bus->dispatch(new GetWorkersCommand())->await();

            /** @var array<ProcessInfo> $processInfos */
            $processInfos = $bus->dispatch(new GetProcessesCommand())->await();

            $workersCount = \count($workerInfos);
            $processesCount = \count($processInfos);
            $totalMemory = \array_sum(\array_map(static fn(ProcessInfo $p): int => $p->memory, $processInfos));
            $startedRows[] = ['Started', $serverStatus->startedAt->format('Y-m-d H:i:s T')];
            $runtimeRows = [
                ['Workers', $workersCount],
                ['Processes', $processesCount],
                ['Memory', formatFileSize($totalMemory)],
            ];
        }

        echo \sprintf("<color;fg=brand;options=bold>❯ 🌸 %s</>\n", Server::NAME);

        $rows = [
            ['Status', $isRunning ? '<color;fg=green>●</> RUNNING' : '<color;fg=red>●</> STOPPED'],
            ...$startedRows,
            ['Version', Server::getVersion()],
            ['PHP', PHP_VERSION],
            ['Event loop', $eventLoop],
            ['Start file', $startFile],
            ...$runtimeRows,
        ];

        echo (new Table(indent: 1))->addRows($rows);

        return 0;
    }
}
