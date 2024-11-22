<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Plugin\System\Command;

use PHPStreamServer\Core\Console\Command;
use PHPStreamServer\Core\Console\Table;
use PHPStreamServer\Core\Internal\MasterProcess;
use PHPStreamServer\Core\Plugin\Plugin;
use PHPStreamServer\Core\Process;
use PHPStreamServer\Core\Server;
use PHPStreamServer\Core\Plugin\Supervisor\Status\SupervisorStatus;
use PHPStreamServer\Core\Plugin\Supervisor\Status\WorkerInfo;
use function PHPStreamServer\Core\getDriverName;

/**
 * @internal
 */
final class StartCommand extends Command
{
    public const COMMAND = 'start';
    public const DESCRIPTION = 'Start server';

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

        $this->assertServerIsNotRunning($args['pidFile']);

        $daemonize = (bool) $this->options->getOption('daemon');

        $masterProcess = new MasterProcess(
            pidFile: $args['pidFile'],
            socketFile: $args['socketFile'],
            plugins: $args['plugins'],
            workers: $args['workers'],
        );

        unset($args);

        $supervisorStatus = $masterProcess->get(SupervisorStatus::class);
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
