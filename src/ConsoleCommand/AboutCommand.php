<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\ConsoleCommand;

use PHPStreamServer\Core\Console\Command;
use PHPStreamServer\Core\Console\Table;
use PHPStreamServer\Core\Plugin\Plugin;
use PHPStreamServer\Core\Server;
use PHPStreamServer\Core\Worker\SupervisedWorker;
use PHPStreamServer\Core\Worker\WorkerFactory;
use PHPStreamServer\Core\WorkerInterface;

use function PHPStreamServer\Core\getDriverName;
use function PHPStreamServer\Core\getStartFile;

class AboutCommand extends Command
{
    final public static function getName(): string
    {
        return 'about';
    }

    final public static function getDescription(): string
    {
        return 'Show server information';
    }

    public function execute(string $pidFile, string $socketFile): int
    {
        $plugins = $this->getPlugins();
        $workers = $this->getWorkers();
        $workerFactories = $this->getWorkerFactories();

        echo \sprintf("<color;fg=brand;options=bold>❯ 🌸 %s</>\n", Server::NAME);
        echo "  PHP application server and process manager\n";
        echo "  https://phpstreamserver.dev/\n";

        echo (new Table(indent: 1))
            ->addRows([
                ['Version:', Server::getVersion()],
                ['PHP:', PHP_VERSION],
                ['Event loop:', getDriverName()],
                ['Start file:', getStartFile()],
                ['PID file:', $pidFile],
                ['Socket file:', $socketFile],
            ])
        ;

        echo "<color;fg=brand;options=bold>❯ Plugins</>\n";

        if (\count($plugins) > 0) {
            echo (new Table(indent: 1))
                ->setHeaderRow([
                    'Plugin',
                    'Description',
                ])
                ->addRows(\array_map(static function (Plugin $p): array {
                    return [
                        (new \ReflectionClass($p))->getShortName(),
                        \sprintf('<color;fg=gray>%s</>', $p::getDescription()),
                    ];
                }, $plugins))
            ;
        } else {
            echo "  No plugins configured\n";
        }

        echo "<color;fg=brand;options=bold>❯ Workers</>\n";

        if (\count($workers) > 0) {
            echo (new Table(indent: 1))
                ->setHeaderRow([
                    'User',
                    'Worker',
                    'Count',
                    'Type',
                ])
                ->addRows(\array_map(static function (WorkerInterface $w): array {
                    $count = $w instanceof SupervisedWorker ? $w->count : 1;
                    $user = $w->getUser();

                    return [
                        $user === 'root' ? $user : "<color;fg=gray>{$user}</>",
                        $w->getName(),
                        (string) $count,
                        "<color;fg=gray>" . (new \ReflectionClass($w))->getShortName() . "</>",
                    ];
                }, $workers))
            ;
        } else {
            echo "  No workers configured\n";
        }

        echo "<color;fg=brand;options=bold>❯ Worker factories</>\n";

        if (\count($workerFactories) > 0) {
            echo (new Table(indent: 1))
                ->setHeaderRow([
                    'ID',
                ])
                ->addRows(\array_map(static function (WorkerFactory $wf): array {
                    return [
                        $wf->id,
                    ];
                }, $workerFactories))
            ;
        } else {
            echo "  No worker factories configured\n";
        }

        return 0;
    }
}
