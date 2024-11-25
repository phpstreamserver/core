<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Plugin\System\Command;

use PHPStreamServer\Core\MessageBus\SocketFileMessageBus;
use PHPStreamServer\Core\Console\Command;
use PHPStreamServer\Core\Console\Table;
use PHPStreamServer\Core\Plugin\Supervisor\Message\GetSupervisorStatusCommand;
use PHPStreamServer\Core\Plugin\Supervisor\Status\SupervisorStatus;
use PHPStreamServer\Core\Plugin\Supervisor\Status\WorkerInfo;

/**
 * @internal
 */
final class WorkersCommand extends Command
{
    public const COMMAND = 'workers';
    public const DESCRIPTION = 'Show workers status';

    public function execute(array $args): int
    {
        /**
         * @var array{pidFile: string, socketFile: string} $args
         */

        $this->assertServerIsRunning($args['pidFile']);

        echo "❯ Workers\n";

        $bus = new SocketFileMessageBus($args['socketFile']);
        $supervisorStatus = $bus->dispatch(new GetSupervisorStatusCommand())->await();
        \assert($supervisorStatus instanceof SupervisorStatus);
        $workers = $supervisorStatus->getWorkers();

        if (\count($workers) > 0) {
            echo (new Table(indent: 1))
                ->setHeaderRow([
                    'User',
                    'Worker',
                    'Count',
                ])
                ->addRows(\array_map(array: $workers, callback: static fn(WorkerInfo $w) => [
                    $w->user === 'root' ? $w->user : "<color;fg=gray>{$w->user}</>",
                    $w->name,
                    $w->count,
                ]));
        } else {
            echo "  <color;bg=yellow> ! </> <color;fg=yellow>There are no workers</>\n";
        }

        return 0;
    }
}
