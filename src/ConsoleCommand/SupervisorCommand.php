<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\ConsoleCommand;

use PHPStreamServer\Core\Command\GetNetworkInfoCommand;
use PHPStreamServer\Core\Command\GetProcessesCommand;
use PHPStreamServer\Core\Command\GetWorkersCommand;
use PHPStreamServer\Core\Console\Command;
use PHPStreamServer\Core\Console\Table;
use PHPStreamServer\Core\MessageBus\ExternalProcessMessageBus;
use PHPStreamServer\Core\Plugin\Supervisor\ProcessInfo;
use PHPStreamServer\Core\Plugin\Supervisor\WorkerInfo;
use PHPStreamServer\Core\Plugin\System\ProcessNetworkInfo;

use function PHPStreamServer\Core\formatDuration;
use function PHPStreamServer\Core\formatFileSize;

class SupervisorCommand extends Command
{
    final public static function getName(): string
    {
        return 'supervisor';
    }

    final public static function getDescription(): string
    {
        return 'List workers and processes';
    }

    public function execute(string $pidFile, string $socketFile): int
    {
        $bus = new ExternalProcessMessageBus($pidFile, $socketFile);

        $workers = $bus->dispatch(new GetWorkersCommand())->await();
        $processes = $bus->dispatch(new GetProcessesCommand())->await();
        $processNetworkInfos = $bus->dispatch(new GetNetworkInfoCommand())->await();

        $processNetworkInfosByPid = [];
        foreach ($processNetworkInfos as $processNetworkInfo) {
            $processNetworkInfosByPid[$processNetworkInfo->pid] = $processNetworkInfo;
        }
        unset($processNetworkInfos);

        echo "<color;fg=brand;options=bold>❯ Workers</>\n";

        if (\count($workers) > 0) {
            echo (new Table(indent: 1))
                ->setHeaderRow([
                    'User',
                    'Worker',
                    'Processes',
                ])
                ->addRows(\array_map(array: $workers, callback: static fn(WorkerInfo $w) => [
                    $w->user === 'root' ? $w->user : "<color;fg=gray>{$w->user}</>",
                    $w->name,
                    $w->processCount,
                ]));
        } else {
            echo "  No workers configured\n";
        }

        echo "<color;fg=brand;options=bold>❯ Processes</>\n";

        if (\count($processes) > 0) {
            \usort($processes, static fn(ProcessInfo $a, ProcessInfo $b) => $a->workerId <=> $b->workerId);

            echo (new Table(indent: 1))
                ->setHeaderRow([
                    'PID',
                    'User',
                    'Worker',
                    'Uptime',
                    'Memory',
                    'Connections',
                    'Requests',
                    'Bytes (RX / TX)',
                    'Status',
                ])
                ->addRows(\array_map(array: $processes, callback: static function (ProcessInfo $p) use ($processNetworkInfosByPid): array {
                    /** @var ProcessNetworkInfo|null $processNetworkInfo */
                    $processNetworkInfo = $processNetworkInfosByPid[$p->pid] ?? null;
                    $requestCount = $processNetworkInfo !== null ? $processNetworkInfo->requests : 0;
                    $connectionCount = $processNetworkInfo !== null ? \count($processNetworkInfo->connections) : 0;
                    $rx = $processNetworkInfo !== null ? $processNetworkInfo->rx : 0;
                    $tx = $processNetworkInfo !== null ? $processNetworkInfo->tx : 0;

                    return [
                        $p->pid,
                        $p->user === 'root' ? $p->user : "<color;fg=gray>{$p->user}</>",
                        $p->name,
                        formatDuration($p->startedAt),
                        $p->memory > 0 ? formatFileSize($p->memory) : '<color;fg=gray>??</>',
                        $processNetworkInfo === null ? '<color;fg=gray>-</>' : (
                            $connectionCount === 0 ? '<color;fg=gray>0</>' : $connectionCount
                        ),
                        $processNetworkInfo === null ? '<color;fg=gray>-</>' : (
                            $requestCount === 0 ? '<color;fg=gray>0</>' : $requestCount
                        ),
                        $processNetworkInfo === null ? '<color;fg=gray>-</>' : (
                            $rx === 0 && $tx === 0 ? \sprintf('<color;fg=gray>%s / %s</>', formatFileSize($rx), formatFileSize($tx)) : \sprintf('%s / %s', formatFileSize($rx), formatFileSize($tx))
                        ),
                        match (true) {
                            $p->blocked => '<color;fg=yellow>●</> BLOCKED',
                            default => '<color;fg=green>●</> OK',
                        },
                    ];
                }));
        } else {
            echo "  No running processes\n";
        }

        return 0;
    }
}
