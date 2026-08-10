<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Internal\Console;

use PHPStreamServer\Core\Console\Command;
use PHPStreamServer\Core\Console\OptionDefinition;
use PHPStreamServer\Core\Console\Options;
use PHPStreamServer\Core\Console\StdoutHandler;
use PHPStreamServer\Core\Console\Table;
use PHPStreamServer\Core\Exception\ServerIsNotRunning;
use PHPStreamServer\Core\Exception\ServerIsRunning;
use PHPStreamServer\Core\Plugin\Plugin;
use PHPStreamServer\Core\Server;
use PHPStreamServer\Core\Worker\WorkerFactory;
use PHPStreamServer\Core\WorkerInterface;
use Revolt\EventLoop;

use function PHPStreamServer\Core\getStartFile;

/**
 * @internal
 */
final readonly class App
{
    public function __construct(private string $pidFile, private string $socketFile)
    {
    }

    /**
     * @param array<Plugin> $plugins
     * @param array<WorkerInterface> $workers
     * @param array<WorkerFactory> $workerFactories
     * @psalm-suppress UndefinedVariable, PossiblyUndefinedVariable
     */
    public function run(array &$plugins, array &$workers, array &$workerFactories): int
    {
        $currentCommand = self::getCurrentCommand();
        $options = self::getOptions();
        $allRegisteredCommands = self::getAllRegisteredCommands($plugins);
        $map = new \WeakMap();
        $map[EventLoop::getDriver()] = [
            'plugins' => $plugins,
            'workers' => $workers,
            'workerFactories' => $workerFactories,
            'options' => $options,
        ];

        // Free memory
        $plugins = [];
        $workers = [];
        $workerFactories = [];

        StdoutHandler::register('php://stdout', 'php://stderr', !$options->hasOption('no-color'), $options->hasOption('quiet'));

        if ($options->hasOption('version')) {
            echo \sprintf("%s\n", Server::getVersion());
            return 0;
        }

        foreach ($allRegisteredCommands as $command) {
            if ($command::getName() !== $currentCommand) {
                continue;
            }

            $command->map = $map;
            $command->configure();

            if ($options->hasOption('help')) {
                $this->showCommandHelp($command, $options->getOptionDefinitions());
                return 0;
            }

            // Free memory
            unset($currentCommand, $options, $allRegisteredCommands, $map);

            try {
                return $command->execute($this->pidFile, $this->socketFile);
            } catch (ServerIsNotRunning) {
                echo \sprintf("<color;fg=red;options=bold>✗</> %s is not running\n", Server::NAME);
                return 1;
            } catch (ServerIsRunning) {
                echo \sprintf("<color;fg=red;options=bold>✗</> %s is already running\n", Server::NAME);
                return 1;
            }
        }

        if ($currentCommand !== null) {
            echo \sprintf("<color;fg=red;options=bold>✗</> Unknown command \"%s\"\n", $currentCommand);
            return 1;
        }

        $this->showApplicationHelp($allRegisteredCommands, $options->getOptionDefinitions());
        return 0;
    }

    private static function getCurrentCommand(): string|null
    {
        $command = $_SERVER['argv'][1] ?? null;
        return $command !== null && !\str_starts_with($command, '-') ? $command : null;
    }

    private static function getOptions(): Options
    {
        return new Options(
            argv: $_SERVER['argv'] ?? [],
            defaultOptionDefinitions: [
                new OptionDefinition('help', 'h', 'Show command help'),
                new OptionDefinition('quiet', 'q', 'Suppress all output'),
                new OptionDefinition('no-color', null, 'Disable ANSI colors'),
                new OptionDefinition('version', null, 'Print the version'),
            ],
        );
    }

    /**
     * @param array<Plugin> $plugins
     * @return array<Command>
     */
    private static function getAllRegisteredCommands(array $plugins): array
    {
        $commands = [];
        foreach ($plugins as $plugin) {
            foreach ($plugin->registerCommands() as $command) {
                $commands[$command::getName()] = $command;
            }
        }

        return $commands;
    }

    /**
     * @param iterable<Command> $commands
     * @param array<OptionDefinition> $options
     */
    private static function showApplicationHelp(iterable $commands, array $options): void
    {
        echo \sprintf("<color;fg=brand;options=bold>🌸 %s</>  <color;options=dim>%s</>\n", Server::NAME, Server::getVersion());
        echo "  PHP application server and process manager\n";
        echo "<color;fg=brand;options=bold>Usage</>\n";
        echo \sprintf("  <color;fg=token>%s</> <command> [options]\n", self::getInvocation());
        echo "<color;fg=brand;options=bold>Commands</>\n";
        echo (new Table(indent: 1))->addRows(self::createCommandsTableRows($commands));
        echo "<color;fg=brand;options=bold>Global options</>\n";
        echo (new Table(indent: 1))->addRows(self::createOptionsTableRows($options));
    }

    /**
     * @param iterable<OptionDefinition> $options
     */
    private static function showCommandHelp(Command $command, iterable $options): void
    {
        echo \sprintf("<color;fg=brand;options=bold>🌸 %s</>  <color;options=dim>%s</>\n", Server::NAME, Server::getVersion());
        echo "  PHP application server and process manager\n";
        echo "<color;fg=brand;options=bold>Description</>\n";
        echo \sprintf("  %s\n", $command::getDescription());
        echo "<color;fg=brand;options=bold>Usage</>\n";
        echo \sprintf("  <color;fg=token>%s %s</> [options]\n", self::getInvocation(), $command::getName());
        echo "<color;fg=brand;options=bold>Options</>\n";
        echo (new Table(indent: 1))->addRows(self::createOptionsTableRows($options));
    }

    private static function getInvocation(): string
    {
        $startFile = $_SERVER['argv'][0] ?? getStartFile();
        if (\preg_match('~^[a-zA-Z0-9_./-]+$~D', $startFile) !== 1) {
            $startFile = \escapeshellarg($startFile);
        }

        return 'php ' . $startFile;
    }

    /**
     * @param iterable<Command> $commands
     */
    private static function createCommandsTableRows(iterable $commands): \Generator
    {
        foreach ($commands as $command) {
            yield [
                \sprintf('<color;fg=token>%s</>', $command::getName()),
                $command::getDescription(),
            ];
        }
    }

    /**
     * @param iterable<OptionDefinition> $options
     */
    private static function createOptionsTableRows(iterable $options): \Generator
    {
        foreach ($options as $option) {
            yield [
                \sprintf('<color;fg=token>%s--%s</>', $option->shortName !== null ? '-' . $option->shortName . ', ' : '    ', $option->name),
                $option->description,
            ];
        }
    }
}
