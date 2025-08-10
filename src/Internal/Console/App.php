<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Internal\Console;

use PHPStreamServer\Core\Console\Command;
use PHPStreamServer\Core\Console\Options;
use PHPStreamServer\Core\Console\Table;
use PHPStreamServer\Core\Exception\ServerIsNotRunning;
use PHPStreamServer\Core\Exception\ServerIsRunning;
use PHPStreamServer\Core\Plugin\Plugin;
use PHPStreamServer\Core\Process;
use PHPStreamServer\Core\Server;
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
     * @param array<Process> $workers
     * @psalm-suppress UndefinedVariable, PossiblyUndefinedVariable
     */
    public function run(array &$plugins, array &$workers): int
    {
        $curentCommand = self::getCurrentCommand();
        $options = self::getOptions();
        $allRegisteredCommands = self::getAllRegisteredCommands($plugins);
        $map = new \WeakMap();
        $map[EventLoop::getDriver()] = [
            'plugins' => $plugins,
            'workers' => $workers,
            'options' => $options,
        ];

        // Free memory
        $plugins = $workers = [];

        StdoutHandler::register('php://stdout', 'php://stderr', !$options->hasOption('no-color'), $options->hasOption('quiet'));

        if ($options->hasOption('version')) {
            echo \sprintf("%s\n", Server::getVersion());
            return 0;
        }

        foreach ($allRegisteredCommands as $command) {
            if ($command::getName() !== $curentCommand) {
                continue;
            }

            $command->map = $map;
            $command->configure();

            if ($options->hasOption('help')) {
                $this->showCommandHelp($command, $options->getOptionDefinitions());
                return 0;
            }

            // Free memory
            unset($curentCommand, $options, $allRegisteredCommands, $map);

            try {
                return $command->execute($this->pidFile, $this->socketFile);
            } catch (ServerIsNotRunning) {
                echo \sprintf("<color;bg=red>%s is not running</>\n", Server::NAME);
                return 1;
            } catch (ServerIsRunning) {
                echo \sprintf("<color;bg=red>%s already running</>\n", Server::NAME);
                return 1;
            }
        }

        if ($curentCommand !== null) {
            echo \sprintf("<color;bg=red>✘ Command \"%s\" does not exist</>\n", $curentCommand);
            return 1;
        }

        $this->showGlobalHelp($allRegisteredCommands, $options->getOptionDefinitions());
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
                new OptionDefinition('help', 'h', 'Show help'),
                new OptionDefinition('quiet', 'q', 'Suppress output'),
                new OptionDefinition('no-color', null, 'Disable colors'),
                new OptionDefinition('version', null, 'Show version'),
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
    private static function showGlobalHelp(iterable $commands, array $options): void
    {
        echo \sprintf("%s (%s)\n", Server::TITLE, Server::getVersion());
        echo "<color;fg=yellow>Usage:</>\n";
        echo \sprintf("  %s <command> [options]\n", \basename(getStartFile()));
        echo "<color;fg=yellow>Commands:</>\n";
        echo (new Table(indent: 1))->addRows(self::createCommandsTableRows($commands));
        echo "<color;fg=yellow>Options:</>\n";
        echo (new Table(indent: 1))->addRows(self::createOptionsTableRows($options));
    }

    /**
     * @param iterable<OptionDefinition> $options
     */
    private static function showCommandHelp(Command $command, iterable $options): void
    {
        echo \sprintf("%s (%s)\n", Server::TITLE, Server::getVersion());
        echo "<color;fg=yellow>Description:</>\n";
        echo \sprintf("  %s\n", $command::getDescription());
        echo "<color;fg=yellow>Usage:</>\n";
        echo \sprintf("  %s %s [options]\n", \basename(getStartFile()), $command::getName());
        echo "<color;fg=yellow>Options:</>\n";
        echo (new Table(indent: 1))->addRows(self::createOptionsTableRows($options));
    }

    /**
     * @param iterable<Command> $commands
     */
    private static function createCommandsTableRows(iterable $commands): \Generator
    {
        foreach ($commands as $command) {
            yield [
                \sprintf('<color;fg=green>%s</>', $command::getName()),
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
                \sprintf('<color;fg=green>%s--%s</>', $option->shortName !== null ? '-' . $option->shortName . ', ' : '    ', $option->name),
                $option->description,
            ];
        }
    }
}
