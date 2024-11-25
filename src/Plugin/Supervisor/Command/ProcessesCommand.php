<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Plugin\Supervisor\Command;

use PHPStreamServer\Core\Console\Command;
use PHPStreamServer\Core\Console\Table;
use PHPStreamServer\Core\MessageBus\SocketFileMessageBus;
use PHPStreamServer\Core\Plugin\Supervisor\Message\GetSupervisorStatusCommand;
use PHPStreamServer\Core\Plugin\Supervisor\Status\ProcessInfo;
use PHPStreamServer\Core\Plugin\Supervisor\Status\SupervisorStatus;
use PHPStreamServer\Core\Plugin\System\Connections\ConnectionsStatus;
use PHPStreamServer\Core\Plugin\System\Message\GetConnectionsStatusCommand;

use function PHPStreamServer\Core\humanFileSize;

/**
 * @internal
 */
final class ProcessesCommand extends Command
{
    public const COMMAND = 'processes';
    public const DESCRIPTION = 'Show processes status';

    public function execute(array $args): int
    {
        /**
         * @var array{pidFile: string, socketFile: string} $args
         */

        $this->assertServerIsRunning($args['pidFile']);

        echo "❯ Processes\n";

        $bus = new SocketFileMessageBus($args['socketFile']);

        $processesStatus = $bus->dispatch(new GetSupervisorStatusCommand())->await();
        \assert($processesStatus instanceof SupervisorStatus);

        $connectionsStatus = $bus->dispatch(new GetConnectionsStatusCommand())->await();
        \assert($connectionsStatus instanceof ConnectionsStatus);

        if ($processesStatus->getProcessesCount() > 0) {
            $processes = $processesStatus->getProcesses();
            \usort($processes, static fn(ProcessInfo $a, ProcessInfo $b) => $a->workerId <=> $b->workerId);

            echo (new Table(indent: 1))
                ->setHeaderRow([
                    'Pid',
                    'User',
                    'Memory',
                    'Worker',
                    'Connections',
                    'Requests',
                    'Bytes (RX / TX)',
                    'Status',
                ])
                ->addRows(\array_map(array: $processes, callback: static function (ProcessInfo $w) use ($connectionsStatus) {
                    $c = $connectionsStatus->getProcessConnectionsInfo($w->pid);

                    return [
                        $w->pid,
                        $w->user === 'root' ? $w->user : "<color;fg=gray>{$w->user}</>",
                        $w->memory > 0 ? humanFileSize($w->memory) : '<color;fg=gray>??</>',
                        $w->name,
                        \count($c->connections) === 0 ? '<color;fg=gray>0</>' : \count($c->connections),
                        $c->requests === 0 ? '<color;fg=gray>0</>' : $c->requests,
                        $c->rx === 0 && $c->tx === 0
                            ? \sprintf('<color;fg=gray>(%s / %s)</>', humanFileSize($c->rx), humanFileSize($c->tx))
                            : \sprintf('(%s / %s)', humanFileSize($c->rx), humanFileSize($c->tx)),
                        match (true) {
                            $w->detached => '[<color;fg=cyan>DETACHED</>]',
                            $w->blocked => '[<color;fg=yellow>BLOCKED</>]',
                            default => '[<color;fg=green>OK</>]',
                        },
                    ];
                }));
        } else {
            echo "  <color;bg=yellow> ! </> <color;fg=yellow>There are no running processes</>\n";
        }

        return 0;
    }
}
