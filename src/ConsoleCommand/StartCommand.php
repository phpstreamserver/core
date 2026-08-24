<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\ConsoleCommand;

use PHPStreamServer\Core\Console\Command;
use PHPStreamServer\Core\Console\CommandContext;
use PHPStreamServer\Core\Console\OptionDefinition;
use PHPStreamServer\Core\Console\Options;
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

    public function getOptionDefinitions(): array
    {
        return [
            new OptionDefinition('daemon', 'd', 'Run in daemon mode'),
        ];
    }

    public function execute(CommandContext $context, Options $options): int
    {
        if (isRunning($context->pidFile)) {
            throw new ServerIsRunning();
        }

        $daemonize = (bool) $options->getOption('daemon');
        $runtimeState = $context->takeRuntimeState();
        $workers = $runtimeState['workers'];

        $masterProcess = new MasterProcess(
            pidFile: $context->pidFile,
            socketFile: $context->socketFile,
            plugins: $runtimeState['plugins'],
            workers: $runtimeState['workers'],
            workerFactories: $runtimeState['workerFactories'],
        );
        unset($runtimeState);

        echo \sprintf("<color;fg=brand;options=bold>❯ 🌸 %s</>\n", Server::NAME);

        echo (new Table(indent: 1))
            ->addRows([
                ['Version:', Server::getVersion()],
                ['PHP:', PHP_VERSION],
                ['Event loop:', getDriverName()],
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

        unset($workers);

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
