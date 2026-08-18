<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\ConsoleCommand;

use PHPStreamServer\Core\Console\Command;
use PHPStreamServer\Core\Console\Table;
use PHPStreamServer\Core\Exception\ServerIsRunning;
use PHPStreamServer\Core\Internal\MasterProcess;
use PHPStreamServer\Core\Server;
use PHPStreamServer\Core\Worker\SupervisedWorker;
use PHPStreamServer\Core\WorkerInterface;

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
        return 'Start the server';
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
            workerFactories: $this->getWorkerFactories(),
        );

        /**
         * @var array<SupervisedWorker> $workers
         * @psalm-suppress UndefinedThisPropertyFetch, PossiblyNullFunctionCall
         */
        $workers = (fn(): array => $this->workers)->bindTo($masterProcess, $masterProcess)();
        $eventLoop = getDriverName();

        echo \sprintf("<color;fg=brand;options=bold>❯ 🌸 %s</>\n", Server::NAME);

        echo (new Table(indent: 1))
            ->addRows([
                ['Version:', Server::getVersion()],
                ['PHP:', PHP_VERSION],
                ['Event loop:', $eventLoop],
            ])
        ;

        echo "<color;fg=brand;options=bold>❯ Workers</>\n";

        if (\count($workers) > 0) {
            echo (new Table(indent: 1))
                ->setHeaderRow([
                    'User',
                    'Worker',
                    'Count',
                ])
                ->addRows(\array_map(static function (WorkerInterface $w): array {
                    $count = $w instanceof SupervisedWorker ? $w->count : 1;
                    $user = $w->getUser();

                    return [
                        $user === 'root' ? $user : "<color;fg=gray>{$user}</>",
                        $w->getName(),
                        $count,
                    ];
                }, $workers))
            ;
        } else {
            echo "  No workers configured\n";
        }

        if (!$daemonize) {
            echo "Press Ctrl+C to stop.\n";
        }

        $exitCode = $masterProcess->run($daemonize);

        if ($daemonize) {
            if ($exitCode === 0) {
                echo \sprintf("<color;fg=green;options=bold>✓</> %s daemon started\n", Server::NAME);
            } else {
                echo \sprintf("<color;fg=red;options=bold>✗</> %s daemon failed to start\n", Server::NAME);
            }
        }

        return $exitCode;
    }
}
