<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Command;

use PHPStreamServer\Core\Console\Command;
use PHPStreamServer\Core\Console\Table;
use PHPStreamServer\Core\Exception\ServerIsRunning;
use PHPStreamServer\Core\Internal\MasterProcess;
use PHPStreamServer\Core\Plugin\Plugin;
use PHPStreamServer\Core\Plugin\Supervisor\Status\SupervisorStatus;
use PHPStreamServer\Core\Plugin\Supervisor\Status\WorkerInfo;
use PHPStreamServer\Core\Process;
use PHPStreamServer\Core\Server;
use function PHPStreamServer\Core\getDriverName;
use function PHPStreamServer\Core\isRunning;

class StartCommand extends Command
{
    final public const COMMAND = 'start';
    final public const DESCRIPTION = 'Start server';

    public function configure(): void
    {
        $this->options->addOptionDefinition('daemon', 'd', 'Run in daemon mode');
    }

    public function execute(array $args): int
    {
        /**
         * @var array{
         *     pidFile: string,
         *     socketFile: string,
         *     plugins: array<Plugin>,
         *     workers: array<Process>,
         *     stopTimeout: int
         * } $args
         */

        if (isRunning($args['pidFile'])) {
            throw new ServerIsRunning();
        }

        $daemonize = (bool) $this->options->getOption('daemon');

        $masterProcess = new MasterProcess(
            pidFile: $args['pidFile'],
            socketFile: $args['socketFile'],
            plugins: $args['plugins'],
            workers: $args['workers'],
        );

        unset($args);

        /** @psalm-suppress UndefinedThisPropertyFetch, PossiblyNullFunctionCall */
        $supervisorStatus = \Closure::bind(
            fn(): SupervisorStatus => $this->masterContainer->getService(SupervisorStatus::class),
            $masterProcess,
            $masterProcess,
        )();
        \assert($supervisorStatus instanceof SupervisorStatus);

        $eventLoop = getDriverName();

        echo "❯ " . Server::TITLE . "\n";

        echo (new Table(indent: 1))
            ->addRows([
                [Server::NAME . ' version:', Server::getVersion()],
                ['PHP version:', PHP_VERSION],
                ['Event loop driver:', $eventLoop],
                ['Workers count:', $supervisorStatus->getWorkersCount()],
            ])
        ;

        echo "❯ Workers\n";

        if ($supervisorStatus->getWorkersCount() > 0) {
            echo (new Table(indent: 1))
                ->setHeaderRow([
                    'User',
                    'Worker',
                    'Count',
                ])
                ->addRows(\array_map(static function (WorkerInfo $w) {
                    return [
                        $w->user,
                        $w->name,
                        $w->count,
                    ];
                }, $supervisorStatus->getWorkers()))
            ;
        } else {
            echo "  <color;bg=yellow> ! </> <color;fg=yellow>There are no workers</>\n";
        }

        if (!$daemonize) {
            echo "Press Ctrl+C to stop.\n";
        }

        return $masterProcess->run($daemonize);
    }
}
