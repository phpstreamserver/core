<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\ConsoleCommand;

use PHPStreamServer\Core\Command\GetConnectionsStatusCommand;
use PHPStreamServer\Core\Command\GetProcessesCommand;
use PHPStreamServer\Core\Command\GetWorkersCommand;
use PHPStreamServer\Core\Console\Command;
use PHPStreamServer\Core\Console\Table;
use PHPStreamServer\Core\MessageBus\ExternalProcessMessageBus;
use PHPStreamServer\Core\Plugin\Supervisor\ProcessInfo;
use PHPStreamServer\Core\Plugin\Supervisor\WorkerInfo;

use function PHPStreamServer\Core\humanFileSize;

class SupervisorCommand extends Command
{
    final public static function getName(): string
    {
        return 'supervisor';
    }

    final public static function getDescription(): string
    {
        return 'Show supervisor status';
    }

    public function execute(string $pidFile, string $socketFile): int
    {
        $bus = new ExternalProcessMessageBus($pidFile, $socketFile);

        $workers = $bus->dispatch(new GetWorkersCommand())->await();
        $processes = $bus->dispatch(new GetProcessesCommand())->await();
        $connectionsStatus = $bus->dispatch(new GetConnectionsStatusCommand())->await();

        echo "❯ Workers\n";

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
            echo "  <color;bg=yellow> ! </> <color;fg=yellow>There are no workers</>\n";
        }

        echo "❯ Processes\n";

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
                ->addRows(\array_map(array: $processes, callback: static function (ProcessInfo $p) use ($connectionsStatus): array {
                    $c = $connectionsStatus->getProcessConnectionsInfo($p->pid);

                    return [
                        $p->pid,
                        $p->user === 'root' ? $p->user : "<color;fg=gray>{$p->user}</>",
                        $p->name,
                        self::formatUptime($p->startedAt),
                        $p->memory > 0 ? humanFileSize($p->memory) : '<color;fg=gray>??</>',
                        $p->external ? '<color;fg=gray>-</>' : (\count($c->connections) === 0 ? '<color;fg=gray>0</>' : \count($c->connections)),
                        $p->external ? '<color;fg=gray>-</>' : ($c->requests === 0 ? '<color;fg=gray>0</>' : $c->requests),
                        $p->external ? '<color;fg=gray>-</>' : (
                            $c->rx === 0 && $c->tx === 0
                                ? \sprintf('<color;fg=gray>(%s / %s)</>', humanFileSize($c->rx), humanFileSize($c->tx))
                                : \sprintf('(%s / %s)', humanFileSize($c->rx), humanFileSize($c->tx))
                        ),
                        match (true) {
                            $p->blocked => '[<color;fg=yellow>BLOCKED</>]',
                            default => '[<color;fg=green>OK</>]',
                        },
                    ];
                }));
        } else {
            echo "  <color;bg=yellow> ! </> <color;fg=yellow>There are no running processes</>\n";
        }


        return 0;
    }

    private static function formatUptime(\DateTimeInterface $startedAt): string
    {
        $seconds = \max(0, \time() - $startedAt->getTimestamp());
        $days = \intdiv($seconds, 86400);
        $hours = \intdiv($seconds % 86400, 3600);
        $minutes = \intdiv($seconds % 3600, 60);

        return match (true) {
            $days > 0 => \sprintf('%dd %dh %dm', $days, $hours, $minutes),
            $hours > 0 => \sprintf('%dh %dm', $hours, $minutes),
            default => \sprintf('%dm', $minutes),
        };
    }
}
