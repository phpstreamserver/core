<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Command;

use PHPStreamServer\Core\Console\Command;
use PHPStreamServer\Core\Console\Table;
use PHPStreamServer\Core\Exception\ServerIsRunning;
use PHPStreamServer\Core\Internal\MasterProcess;
use PHPStreamServer\Core\Process;
use PHPStreamServer\Core\Server;
use PHPStreamServer\Core\Worker\WorkerProcess;

use function PHPStreamServer\Core\getDriverName;
use function PHPStreamServer\Core\isRunning;

class StartCommand extends Command
{
    final public static function getName(): string
    {
        return 'start';
    }

    final public static function getDescription(): string
    {
        return 'Start server';
    }

    public function configure(): void
    {
        $this->addOptionDefinition('daemon', 'd', 'Run in daemon mode');
    }

    public function execute(string $pidFile, string $socketFile): int
    {
        if (isRunning($pidFile)) {
            throw new ServerIsRunning();
        }

        $daemonize = (bool) $this->getOption('daemon');

        $masterProcess = new MasterProcess(
            pidFile: $pidFile,
            socketFile: $socketFile,
            plugins: $this->getPlugins(),
            workers: $this->getWorkers(),
        );

        /**
         * @var array<WorkerProcess> $workers
         * @psalm-suppress UndefinedThisPropertyFetch, PossiblyNullFunctionCall
         */
        $workers = (fn(): array => $this->workers)->bindTo($masterProcess, $masterProcess)();
        $workers = \array_filter(array: $workers, callback: static fn(Process $worker) => $worker instanceof WorkerProcess);

        $eventLoop = getDriverName();

        echo "❯ " . Server::TITLE . "\n";

        echo (new Table(indent: 1))
            ->addRows([
                [Server::NAME . ' version:', Server::getVersion()],
                ['PHP version:', PHP_VERSION],
                ['Event loop driver:', $eventLoop],
                ['Workers count:', \count($workers)],
            ])
        ;

        echo "❯ Workers\n";

        if (\count($workers) > 0) {
            echo (new Table(indent: 1))
                ->setHeaderRow([
                    'User',
                    'Worker',
                    'Count',
                ])
                ->addRows(\array_map(static function (WorkerProcess $w): array {
                    return [
                        $w->getUser(),
                        $w->name,
                        $w->count,
                    ];
                }, $workers))
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
