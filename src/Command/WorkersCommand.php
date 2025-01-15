<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Command;

use PHPStreamServer\Core\Console\Command;
use PHPStreamServer\Core\Console\Table;
use PHPStreamServer\Core\Message\GetSupervisorStatusCommand;
use PHPStreamServer\Core\MessageBus\ExternalProcessMessageBus;
use PHPStreamServer\Core\Plugin\Supervisor\Status\SupervisorStatus;
use PHPStreamServer\Core\Plugin\Supervisor\Status\WorkerInfo;

class WorkersCommand extends Command
{
    final public const COMMAND = 'workers';
    final public const DESCRIPTION = 'Show workers status';

    public function execute(array $args): int
    {
        /**
         * @var array{pidFile: string, socketFile: string} $args
         */

        $bus = new ExternalProcessMessageBus($args['pidFile'], $args['socketFile']);

        echo "❯ Workers\n";

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
